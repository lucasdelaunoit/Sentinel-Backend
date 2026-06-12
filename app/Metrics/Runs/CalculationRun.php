<?php

namespace App\Metrics\Runs;

use App\Metrics\Snapshots\MetricScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One recalculation run for a scope (project / user / org).
 *
 * The latest queued row doubles as the debounce lock: while a fresh queued run
 * exists for a scope, repeat triggers are dropped — the pending job reads fresh
 * data when it executes, so it already covers them.
 */
class CalculationRun extends Model
{
    /** A queued run older than this is considered lost (worker died, dispatch failed) and stops debouncing. */
    public const PENDING_WINDOW_MINUTES = 10;

    protected $fillable = [
        'scope_type',
        'scope_id',
        'status',
        'total_items',
        'processed_items',
        'error',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * <summary>
     *  Scope rows to one (scope_type, scope_id) pair. scope_id is null for org-wide runs.
     * </summary>
     */
    public function scopeForScope(Builder $query, MetricScope $scope, ?int $scopeId): Builder
    {
        return $query->where('scope_type', $scope->value)
            ->when(
                $scopeId === null,
                fn(Builder $q) => $q->whereNull('scope_id'),
                fn(Builder $q) => $q->where('scope_id', $scopeId),
            );
    }

    /**
     * <summary>
     *  True while this run still acts as a debounce lock — queued recently enough
     *  that the delayed job is trusted to be alive.
     * </summary>
     */
    public function isPending(): bool
    {
        return $this->status === CalculationRunStatus::Queued->value
            && $this->queued_at->gt(now()->subMinutes(self::PENDING_WINDOW_MINUTES));
    }
}
