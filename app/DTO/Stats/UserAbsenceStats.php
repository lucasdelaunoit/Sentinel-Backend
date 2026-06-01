<?php

namespace App\DTO\Stats;

use App\Metrics\Stat;

/**
 * Typed DTO returned by AbsenceManager::getUserAbsenceStats — wire shape for GET /users/{user}/absences/stats.
 */
final readonly class UserAbsenceStats
{
    public function __construct(
        public Stat $totalAbsences,
        public Stat $daysOff,
        public Stat $upcoming,
    ) {}

    public function toArray(): array
    {
        return [
            'total_absences' => $this->totalAbsences->toArray(),
            'days_off' => $this->daysOff->toArray(),
            'upcoming' => $this->upcoming->toArray(),
        ];
    }
}
