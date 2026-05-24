<?php

namespace App\Metrics\Scales;

use App\Metrics\Severity;

/**
 * Team availability tier — % of team members currently available (not absent).
 * Higher = better.
 *   <50 critical · <75 partial · ≥75 operational.
 */
enum TeamAvailabilityScale: string implements Scale
{
    case CRITICAL = 'critical';
    case PARTIAL = 'partial';
    case OPERATIONAL = 'operational';

    public static function fromRaw(float|int $pct): self
    {
        return match (true) {
            $pct < 50 => self::CRITICAL,
            $pct < 75 => self::PARTIAL,
            default => self::OPERATIONAL,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CRITICAL => 'Critical',
            self::PARTIAL => 'Partial',
            self::OPERATIONAL => 'Operational',
        };
    }

    public function severity(): Severity
    {
        return match ($this) {
            self::CRITICAL => Severity::CRITICAL,
            self::PARTIAL => Severity::WARNING,
            self::OPERATIONAL => Severity::OK,
        };
    }
}
