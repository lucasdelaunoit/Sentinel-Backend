<?php

namespace App\Metrics\Scales;

/**
 * Fragility tier (0-100, higher = worse). Breakpoints match the spec:
 *   ≤20 solid · ≤40 stable · ≤60 stretched · ≤80 fragile · >80 critical.
 */
enum FragilityScale: string implements Scale
{
    case SOLID = 'solid';
    case STABLE = 'stable';
    case STRETCHED = 'stretched';
    case FRAGILE = 'fragile';
    case CRITICAL = 'critical';

    public static function fromRaw(float|int $raw): self
    {
        return match (true) {
            $raw <= 20 => self::SOLID,
            $raw <= 40 => self::STABLE,
            $raw <= 60 => self::STRETCHED,
            $raw <= 80 => self::FRAGILE,
            default => self::CRITICAL,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SOLID => 'Healthy',
            self::STABLE => 'Stable',
            self::STRETCHED => 'Strained',
            self::FRAGILE => 'Weak',
            self::CRITICAL => 'Critical',
        };
    }

    public function severity(): Severity
    {
        return match ($this) {
            self::SOLID, self::STABLE => Severity::OK,
            self::STRETCHED => Severity::WARNING,
            self::FRAGILE, self::CRITICAL => Severity::CRITICAL,
        };
    }
}
