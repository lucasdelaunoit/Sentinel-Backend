<?php

namespace App\Metrics\Calculators;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Computes today's headcount picture — total / available / absent / critical
 * absences (an absent user assigned to a project whose bus factor ≤ 1).
 */
class TeamAvailabilityCalculator
{
    /**
     * @return array{
     *     total: int,
     *     available: int,
     *     absent: int,
     *     critical_absent: int,
     *     insight: string,
     * }
     */
    public function compute(): array
    {
        $today = Carbon::today();
        $total = User::count();

        $absentIds = User::whereHas('absences', fn($q) =>
            $q->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
        )->pluck('id');

        $absent = $absentIds->count();
        $available = $total - $absent;

        $criticalAbsent = $absent > 0
            ? DB::table('project_users')
                ->join('projects', 'project_users.project_id', '=', 'projects.id')
                ->whereIn('project_users.user_id', $absentIds)
                ->whereNotNull('projects.started_at')
                ->whereDate('projects.started_at', '<=', now())
                ->whereNull('projects.paused_at')
                ->whereNull('projects.completed_at')
                ->whereNull('projects.archived_at')
                ->where('projects.bus_factor', '<=', 1)
                ->distinct()
                ->count('project_users.user_id')
            : 0;

        $insight = match (true) {
            $criticalAbsent > 0 => "{$criticalAbsent} critical employee" . ($criticalAbsent > 1 ? 's' : '') . ' absent',
            $absent > 0 => "{$absent} employee" . ($absent > 1 ? 's' : '') . ' absent',
            default => 'Fully operational',
        };

        return [
            'total' => $total,
            'available' => $available,
            'absent' => $absent,
            'critical_absent' => $criticalAbsent,
            'insight' => $insight,
        ];
    }
}
