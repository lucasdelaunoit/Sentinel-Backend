<?php

namespace App\Support;

use App\Metrics\Scale;
use App\Metrics\Severity;

/**
 * Single shape returned by Manager for every dashboard / KPI stat.
 * Resource layer just calls toArray() — no formatting work in the Resource.
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
