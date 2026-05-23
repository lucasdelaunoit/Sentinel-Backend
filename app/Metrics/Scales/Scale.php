<?php

namespace App\Metrics\Scales;

use App\Metrics\Severity;

interface Scale
{
    /** Human-facing tier label ("Excellent", "Fragile", ...). */
    public function label(): string;

    /** UI severity grade. */
    public function severity(): Severity;
}
