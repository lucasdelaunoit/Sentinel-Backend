<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyHoliday extends Model
{
    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'recurring',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'recurring'  => 'boolean',
    ];
}
