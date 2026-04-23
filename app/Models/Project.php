<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;
    protected $fillable = [
        'name',
        'description',
        'status',
        'progress',
        'risk_score',
        'bus_factor',
        'health',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'date',
        'ended_at' => 'date',
    ];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'project_employees')
            ->withTimestamps();
    }

    public function skillRequirements(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'project_skill_reqs')
            ->withPivot('required_level')
            ->withTimestamps();
    }

    public function simulations(): HasMany
    {
        return $this->hasMany(Simulation::class);
    }
}
