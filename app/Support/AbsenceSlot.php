<?php

namespace App\Support;

use App\Enums\AbsenceHalf;
use Carbon\Carbon;

/**
 * Linearises a (date, half) pair to an integer "slot" so half-day absence ranges
 * can be compared with plain arithmetic. Every day owns two slots:
 *   morning  → dayIndex * 2
 *   afternoon → dayIndex * 2 + 1
 *
 * An absence is the inclusive range [startSlot … endSlot]; days = (end - start + 1) / 2.
 */
final class AbsenceSlot
{
    /** Start boundary slot. Null half defaults to morning (legacy full-day). */
    public static function start(mixed $date, AbsenceHalf|string|null $half): int
    {
        return self::index($date, self::value($half) ?? AbsenceHalf::Morning->value);
    }

    /** End boundary slot. Null half defaults to afternoon (legacy full-day). */
    public static function end(mixed $date, AbsenceHalf|string|null $half): int
    {
        return self::index($date, self::value($half) ?? AbsenceHalf::Afternoon->value);
    }

    /** Inclusive slot intervals [aStart,aEnd] and [bStart,bEnd] intersect. */
    public static function overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return $aStart <= $bEnd && $bStart <= $aEnd;
    }

    private static function index(mixed $date, string $half): int
    {
        $days = (int) floor(Carbon::parse($date)->startOfDay()->getTimestamp() / 86400);

        return $days * 2 + ($half === AbsenceHalf::Afternoon->value ? 1 : 0);
    }

    private static function value(AbsenceHalf|string|null $half): ?string
    {
        return $half instanceof AbsenceHalf ? $half->value : $half;
    }
}
