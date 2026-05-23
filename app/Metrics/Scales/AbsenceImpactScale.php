<?php

namespace App\Metrics\Scales;

use App\Metrics\Severity;

/**
 * Absence impact tier — count of skills newly uncovered because of
 * active absences. Higher = worse.
 *   0 none · 1-2 minor · 3-5 elevated · ≥6 severe.
 */
enum AbsenceImpactScale: string implements Scale
{
    case NONE = 'none';
    case MINOR = 'minor';
    case ELEVATED = 'elevated';
    case SEVERE = 'severe';

    public static function fromRaw(float|int $count): self
    {
        return match (true) {
            $count <= 0 => self::NONE,
            $count <= 2 => self::MINOR,
            $count <= 5 => self::ELEVATED,
            default => self::SEVERE,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'None',
            self::MINOR => 'Minor',
            self::ELEVATED => 'Elevated',
            self::SEVERE => 'Severe',
        };
    }

    public function severity(): Severity
    {
        return match ($this) {
            self::NONE => Severity::OK,
            self::MINOR => Severity::WARNING,
            self::ELEVATED, self::SEVERE => Severity::CRITICAL,
        };
    }
}
