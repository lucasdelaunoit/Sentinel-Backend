<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'fragility_raw',
        'team_availability_raw',
        'knowledge_coverage_raw',
        'bus_factor',
        'absence_impact_raw',
        'started_at',
        'deadline',
        'paused_at',
        'completed_at',
        'archived_at',
    ];

    protected $casts = [
        'started_at' => 'date',
        'deadline' => 'date',
        'paused_at' => 'datetime',
        'completed_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    protected $appends = ['status'];

    /**
     * <summary>
     *  Derived lifecycle status. Read-only — mutate by setting the underlying timestamp columns
     *  (paused_at / completed_at / archived_at) or started_at via the dedicated action endpoints.
     * </summary>
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (): ProjectStatus {
            if ($this->archived_at !== null)  return ProjectStatus::Archived;
            if ($this->completed_at !== null) return ProjectStatus::Completed;
            if ($this->paused_at !== null) return ProjectStatus::Paused;
            if ($this->started_at !== null && $this->started_at->isPast()) return ProjectStatus::Active;
            return ProjectStatus::Planned;
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_users')
            ->withTimestamps();
    }

    public function skillRequirements(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'project_skill_reqs')
            ->withPivot('required_level')
            ->withTimestamps();
    }

    /**
     * <summary>
     *  Scope to projects in Active lifecycle state — started, not paused/completed/archived.
     *  Use this instead of where('status', 'active') because status is a derived attribute, not a column.
     * </summary>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('started_at')
            ->whereDate('started_at', '<=', now())
            ->whereNull('paused_at')
            ->whereNull('completed_at')
            ->whereNull('archived_at');
    }

    /**
     * <summary>
     *  Filter by derived lifecycle status. Translates a ProjectStatus into the underlying
     *  timestamp predicates, mirroring the precedence in the status() accessor
     *  (archived > completed > paused > active > planned). Use this instead of
     *  where('status', ...) because status is a derived attribute, not a column.
     * </summary>
     */
    public function scopeWhereStatus(Builder $query, ProjectStatus $status): Builder
    {
        return match ($status) {
            ProjectStatus::Archived => $query->whereNotNull('archived_at'),
            ProjectStatus::Completed => $query->whereNull('archived_at')
                ->whereNotNull('completed_at'),
            ProjectStatus::Paused => $query->whereNull('archived_at')
                ->whereNull('completed_at')
                ->whereNotNull('paused_at'),
            ProjectStatus::Active => $query->active(),
            ProjectStatus::Planned => $query->whereNull('archived_at')
                ->whereNull('completed_at')
                ->whereNull('paused_at')
                ->where(fn(Builder $q) => $q->whereNull('started_at')
                    ->orWhereDate('started_at', '>', now())),
        };
    }

    /**
     * <summary>
     *  Order rows by derived lifecycle status using a SQL CASE that mirrors the
     *  status() accessor precedence. Ranks planned(0) < active(1) < paused(2) <
     *  completed(3) < archived(4). Use this because status is not a real column.
     * </summary>
     */
    public function scopeOrderByStatus(Builder $query, bool $descending = false): Builder
    {
        return $query->orderByRaw(
            'CASE
                WHEN archived_at IS NOT NULL THEN 4
                WHEN completed_at IS NOT NULL THEN 3
                WHEN paused_at IS NOT NULL THEN 2
                WHEN started_at IS NOT NULL AND started_at <= ? THEN 1
                ELSE 0
            END ' . ($descending ? 'DESC' : 'ASC'),
            [now()->toDateString()]
        );
    }

    /**
     * <summary>
     *  Order rows by derived linear progress using a SQL expression that mirrors
     *  getProgressAttribute(). Use this because progress is not a real column.
     * </summary>
     */
    public function scopeOrderByProgress(Builder $query, bool $descending = false): Builder
    {
        return $query->orderByRaw(
            'CASE
                WHEN completed_at IS NOT NULL THEN 100
                WHEN started_at IS NULL THEN 0
                WHEN deadline IS NULL THEN 50
                WHEN DATEDIFF(deadline, started_at) <= 0 THEN 100
                ELSE LEAST(100, GREATEST(0, DATEDIFF(?, started_at) / DATEDIFF(deadline, started_at) * 100))
            END ' . ($descending ? 'DESC' : 'ASC'),
            [now()->toDateString()]
        );
    }

    /**
     * <summary>
     *  Linear time-based progress derived from started_at and deadline.
     *  Returns 100 if completed, 0 if not started, ratio elapsed/total otherwise.
     * </summary>
     */
    public function getProgressAttribute(): float
    {
        if ($this->completed_at !== null) return 100.0;
        if ($this->started_at === null)   return 0.0;
        if ($this->deadline === null)     return 50.0;

        $total = $this->started_at->diffInDays($this->deadline);
        if ($total <= 0) return 100.0;

        $elapsed = $this->started_at->diffInDays(now());
        return max(0.0, min(100.0, ($elapsed / $total) * 100));
    }
}
