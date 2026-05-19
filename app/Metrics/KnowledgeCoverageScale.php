<?php

namespace App\Metrics;

/**
 * Knowledge coverage tier — % of required skills that are 'safe'.
 * Higher = better.
 *   <50 critical · <75 thin · <90 stable · ≥90 strong.
 */
enum KnowledgeCoverageScale: string implements Scale
{
    case CRITICAL = 'critical';
    case THIN = 'thin';
    case STABLE = 'stable';
    case STRONG = 'strong';

    public static function fromRaw(float|int $pct): self
    {
        return match (true) {
            $pct < 50 => self::CRITICAL,
            $pct < 75 => self::THIN,
            $pct < 90 => self::STABLE,
            default => self::STRONG,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CRITICAL => 'Critical',
            self::THIN => 'Thin',
            self::STABLE => 'Stable',
            self::STRONG => 'Strong',
        };
    }

    public function severity(): Severity
    {
        return match ($this) {
            self::CRITICAL => Severity::CRITICAL,
            self::THIN => Severity::WARNING,
            self::STABLE, self::STRONG => Severity::OK,
        };
    }
}
