<?php

namespace App\Metrics\Scales;

use App\Metrics\Severity;

/**
 * User criticality tier (0-100). Breakpoints:
 *   <30 low_risk · <60 notable · ≥60 critical.
 */
enum CriticalityScale: string implements Scale
{
    case LowRisk = 'low_risk';
    case Notable = 'notable';
    case Critical = 'critical';

    public static function fromRaw(int $score): self
    {
        return match (true) {
            $score >= 60 => self::Critical,
            $score >= 30 => self::Notable,
            default => self::LowRisk,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LowRisk => 'Low Risk',
            self::Notable => 'Notable',
            self::Critical => 'Critical',
        };
    }

    public function severity(): Severity
    {
        return match ($this) {
            self::LowRisk => Severity::OK,
            self::Notable => Severity::WARNING,
            self::Critical => Severity::CRITICAL,
        };
    }
}
