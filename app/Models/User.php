<?php

namespace App\Models;

use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'department_id',
        'firstname',
        'lastname',
        'email',
        'phone',
        'password',
        'title',
        'criticality_raw',
        'bus_factor_in_org_raw',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = ['status'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getStatusAttribute(): UserStatus
    {
        $today = now()->toDateString();
        $isAbsent = $this->absences()
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->exists();

        return $isAbsent ? UserStatus::Away : UserStatus::Available;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'user_skills')
            ->withPivot('level')
            ->withTimestamps();
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_users')
            ->withTimestamps();
    }

    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    public function simulations(): BelongsToMany
    {
        return $this->belongsToMany(Simulation::class, 'simulation_absences')
            ->withTimestamps();
    }
}
