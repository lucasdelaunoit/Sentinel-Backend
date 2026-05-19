<?php

namespace App\Metrics;

/**
 * Trajectory tier (0-100, higher = better). Breakpoints match the spec:
 *   ≤20 off_course · ≤40 drifting · ≤60 wobbling · ≤80 on_track · >80 cruising.
 */
enum TrajectoryScale: string implements Scale
{
    case OffCourse = 'off_course';
    case Drifting  = 'drifting';
    case Wobbling  = 'wobbling';
    case OnTrack   = 'on_track';
    case Cruising  = 'cruising';

    public static function fromRaw(float|int $raw): self
    {
        return match (true) {
            $raw <= 20 => self::OffCourse,
            $raw <= 40 => self::Drifting,
            $raw <= 60 => self::Wobbling,
            $raw <= 80 => self::OnTrack,
            default    => self::Cruising,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OffCourse => 'Off Track',
            self::Drifting  => 'Slipping',
            self::Wobbling  => 'Wobbling',
            self::OnTrack   => 'On Track',
            self::Cruising  => 'Cruising',
        };
    }

    public function severity(): string
    {
        return match ($this) {
            self::OffCourse, self::Drifting => 'critical',
            self::Wobbling                  => 'warning',
            self::OnTrack, self::Cruising   => 'ok',
        };
    }
}
