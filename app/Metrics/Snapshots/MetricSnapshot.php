<?php

namespace App\Metrics\Snapshots;

use App\Metrics\Severity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Time-series row for a single metric value at a point in time.
 *
 * One row per (scope_type, scope_id, metric) per capture. Writers should never
 * UPDATE existing rows — every capture is a new row so history stays intact.
 */
class MetricSnapshot extends Model
{
    protected $fillable = [
        'scope_type',
        'scope_id',
        'metric',
        'value_raw',
        'value_label',
        'severity',
        'meta',
        'captured_at',
    ];

    protected $casts = [
        'scope_type' => MetricScope::class,
        'metric' => MetricKey::class,
        'severity' => Severity::class,
        'value_raw' => 'float',
        'scope_id' => 'integer',
        'meta' => 'array',
        'captured_at' => 'datetime',
    ];

    public function scopeForScope(Builder $q, MetricScope $scope, ?int $scopeId): Builder
    {
        return $q->where('scope_type', $scope->value)->where('scope_id', $scopeId);
    }

    public function scopeForMetric(Builder $q, MetricKey $metric): Builder
    {
        return $q->where('metric', $metric->value);
    }
}
