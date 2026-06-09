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
}
