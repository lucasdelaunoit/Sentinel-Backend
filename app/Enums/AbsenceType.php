<?php

namespace App\Enums;

enum AbsenceType: string
{
    case Vacation = 'vacation';
    case Conference = 'conference';
    case Training = 'training';
    case Parental = 'parental';
    case Sabbatical = 'sabbatical';
    case Other = 'other';
}
