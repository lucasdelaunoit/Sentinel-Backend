<?php

namespace App\Metrics;

use App\Metrics\Scales\Scale;
use App\Metrics\Snapshots\MetricSnapshot;

/**
 * Single, immutable shape for every metric value shipped over the wire.
 *
 * Wire shape (matches the contract every /stats route follows):
 *   {
 *     "value":     "Healthy",   // display label
 *     "value_raw": 98,          // numeric form for tooltips / math
 *     "insight":   "Doing well",// optional context one-liner
 *     "severity":  "ok"         // ok | warning | critical (drives traffic light)
 *   }
 *
 * Lives under App\Metrics because it composes a Scale + a Severity — the
 * two enums it depends on. Resource layer never builds a Stat; it just
 * receives a typed DTO (DashboardStats, UserStats, ...) and serializes.
 */
final readonly class Stat
{
    public function __construct(
        public string $value,
        public int|float|string $valueRaw,
        public Severity $severity,
        public ?string $insight = null,
    ) {}

    /**
     * Build a Stat where the display label comes from a Scale enum
     * (fragility tier, criticality tier, ...).
     */
    public static function fromScale(Scale $scale, int|float|string $raw, ?string $insight = null): self
    {
        return new self($scale->label(), $raw, $scale->severity(), $insight);
    }

    /**
     * Build a Stat with a custom display string but severity driven by a Scale.
     * Used for ratios ("7/8"), percentages ("89%"), or counts where the
     * numeric form is more informative than the tier label.
     */
    public static function display(string $value, int|float|string $raw, Scale $scale, ?string $insight = null): self
    {
        return new self($value, $raw, $scale->severity(), $insight);
    }

    /**
     * Rehydrate a Stat from a persisted MetricSnapshot row — used by readers that
     * pull the latest captured value instead of recomputing live.
     */
    public static function fromSnapshot(MetricSnapshot $snap): self
    {
        return new self(
            value: (string) $snap->value_label,
            valueRaw: $snap->value_raw,
            severity: $snap->severity,
            insight: $snap->meta['insight'] ?? null,
        );
    }

    /**
     * Placeholder Stat used by snapshot readers when no row has been captured yet.
     */
    public static function placeholder(string $value = '—', ?string $insight = 'Not yet computed'): self
    {
        return new self($value, 0, \App\Metrics\Severity::OK, $insight);
    }

    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'value_raw' => $this->valueRaw,
            'insight' => $this->insight,
            'severity' => $this->severity->value,
        ];
    }
}
