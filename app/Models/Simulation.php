<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Simulation extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'description',
        'result'
    ];

    protected $casts = [
        'result' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function absentEmployees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'simulation_leaves')
            ->withTimestamps();
    }
}
