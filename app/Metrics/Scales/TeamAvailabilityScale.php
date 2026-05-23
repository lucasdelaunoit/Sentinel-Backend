<?php

namespace App\Metrics\Scales;

use App\Metrics\Severity;

/**
 * Team availability tier — driven by criticalAbsences (>0 critical),
 * then anyAbsence (>0 warning), else ok. Built with named constructor
 * rather than fromRaw because severity depends on more than one number.
 */
enum TeamAvailabilityScale: string implements Scale
{
    case OPERATIONAL = 'operational';
    case PARTIAL = 'partial';
    case CRITICAL = 'critical';

    public static function fromCounts(int $absent, int $criticalAbsent): self
    {
        return match (true) {
            $criticalAbsent > 0 => self::CRITICAL,
            $absent > 0 => self::PARTIAL,
            default => self::OPERATIONAL,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OPERATIONAL => 'Operational',
            self::PARTIAL => 'Partial',
            self::CRITICAL => 'Critical',
        };
    }

    public function severity(): Severity
    {
        return match ($this) {
            self::OPERATIONAL => Severity::OK,
            self::PARTIAL => Severity::WARNING,
            self::CRITICAL => Severity::CRITICAL,
        };
    }
}
