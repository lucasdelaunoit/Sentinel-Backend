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
        'name',
        'email',
        'password',
        'title',
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
            'password'          => 'hashed',
        ];
    }

    public function getStatusAttribute(): UserStatus
    {
        $today = now()->toDateString();
        $onLeave = $this->leaves()
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->exists();

        return $onLeave ? UserStatus::Away : UserStatus::Available;
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
