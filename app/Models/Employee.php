<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;
    protected $fillable = [
        'department_id',
        'name',
        'email',
        'title',
    ];

    protected $appends = ['status'];

    public function getStatusAttribute(): EmployeeStatus
    {
        $today = now()->toDateString();
        $onLeave = $this->leaves()
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->exists();

        return $onLeave ? EmployeeStatus::Away : EmployeeStatus::Available;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'employee_skills')
            ->withPivot('level')
            ->withTimestamps();
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_employees')
            ->withTimestamps();
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }

    public function simulations(): BelongsToMany
    {
        return $this->belongsToMany(Simulation::class, 'simulation_leaves')
            ->withTimestamps();
    }
}
