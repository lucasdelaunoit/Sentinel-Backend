<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rule extends Model
{
    protected $fillable = [
        'name',
        'type',
        'scope_type',
        'scope_id',
        'params',
        'enabled',
    ];

    protected $casts = [
        'params'   => 'array',
        'enabled'  => 'boolean',
        'scope_id' => 'integer',
    ];
}
