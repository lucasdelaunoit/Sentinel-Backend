<?php

namespace App\Models;

use Database\Factories\SimulationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Simulation extends Model
{
    /** @use HasFactory<SimulationFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'result',
    ];

    protected $casts = [
        'result' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function absentUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'simulation_leaves')
            ->withTimestamps();
    }
}
