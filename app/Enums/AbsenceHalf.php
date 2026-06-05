<?php

namespace App\Enums;

/**
 * Which half of a day an absence boundary falls on.
 *
 * An absence spans the inclusive range [start_date.start_half … end_date.end_half].
 */
enum AbsenceHalf: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';

    /** Ordering rank within a day: morning before afternoon. */
    public function rank(): int
    {
        return $this === self::Afternoon ? 1 : 0;
    }
}
