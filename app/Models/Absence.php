<?php

namespace App\Models;

use App\Enums\AbsenceHalf;
use App\Enums\AbsenceType;
use Database\Factories\AbsenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Absence extends Model
{
    /** @use HasFactory<AbsenceFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'start_date',
        'start_half',
        'end_date',
        'end_half',
        'type',
        'reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'start_half' => AbsenceHalf::class,
        'end_half' => AbsenceHalf::class,
        'type' => AbsenceType::class,
        'normalized_days' => 'float',
        'normalized_frozen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
