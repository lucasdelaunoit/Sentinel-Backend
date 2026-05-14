<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Planned   = 'planned';
    case Active    = 'active';
    case Paused    = 'paused';
    case Completed = 'completed';
    case Archived  = 'archived';
}
