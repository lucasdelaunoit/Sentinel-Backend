<?php

namespace App\Metrics;

/**
 * Shared shape for every risk scale. A scale maps a raw numeric metric
 * to a discrete tier and exposes the tier's display label + UI severity.
 *
 * Implementations are enums (one per metric family) — see FragilityScale,
 * CriticalityScale, BusFactorScale. Each enum provides
 * its own `from*` static factory because input shapes differ (continuous
 * raw score vs integer count).
 */
interface Scale
{
    /** Human-facing label for the tier ("Excellent", "Off Track", ...). */
    public function label(): string;

    /** UI severity bucket: ok | warning | critical. */
    public function severity(): string;
}
