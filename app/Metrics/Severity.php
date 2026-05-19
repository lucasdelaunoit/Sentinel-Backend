<?php

namespace App\Metrics;

enum Severity: string
{
    case OK = 'ok';
    case WARNING = 'warning';
    case CRITICAL = 'critical';
}
