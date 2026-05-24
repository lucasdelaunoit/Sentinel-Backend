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

    public function simulations(): HasMany
    {
        return $this->hasMany(Simulation::class);
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
