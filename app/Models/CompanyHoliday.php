<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyHoliday extends Model
{
    protected $fillable = [
        'name',
        'date',
        'recurring',
    ];

    protected $casts = [
        'date'      => 'date',
        'recurring' => 'boolean',
    ];
}
