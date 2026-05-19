<?php

namespace App\Metrics;

/**
 * Fragility tier (0-100, higher = worse). Breakpoints match the spec:
 *   ≤20 solid · ≤40 stable · ≤60 stretched · ≤80 fragile · >80 critical.
 */
enum FragilityScale: string implements Scale
{
    case Solid     = 'solid';
    case Stable    = 'stable';
    case Stretched = 'stretched';
    case Fragile   = 'fragile';
    case Critical  = 'critical';

    public static function fromRaw(float|int $raw): self
    {
        return match (true) {
            $raw <= 20 => self::Solid,
            $raw <= 40 => self::Stable,
            $raw <= 60 => self::Stretched,
            $raw <= 80 => self::Fragile,
            default    => self::Critical,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Solid     => 'Excellent',
            self::Stable    => 'Good',
            self::Stretched => 'Watch',
            self::Fragile   => 'Fragile',
            self::Critical  => 'Critical',
        };
    }

    public function severity(): string
    {
        return match ($this) {
            self::Solid, self::Stable     => 'ok',
            self::Stretched               => 'warning',
            self::Fragile, self::Critical => 'critical',
        };
    }
}
