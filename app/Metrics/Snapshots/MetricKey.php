<?php

namespace App\Metrics\Snapshots;

/**
 * Identifies what metric a snapshot row holds.
 * Add a case here before capturing a new metric — keeps the metric_snapshots
 * `metric` column constrained to known values.
 *
 * Scope (phase 1): project-only metrics (fragility, bus_factor).
 */
enum MetricKey: string
{
    case Fragility = 'fragility';
    case BusFactor = 'bus_factor';
}
