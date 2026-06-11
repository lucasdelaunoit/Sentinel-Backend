<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\User;
use Carbon\Carbon;

/**
 * <summary>
 *  Planning roster + scenario application. Produces the month payload used by the Gantt
 *  view (users, absences, capacity_today) and persists applied scenarios as planned leave.
 *  The what-if impact engine lives in PlanningSimulationService.
 * </summary>
 */
class PlanningService
{
    /* ─────────────────────── Month payload ─────────────────────── */

    /**
     * <summary>
     *  Build the month roster payload for the Gantt view: every user with their absences
     *  overlapping the month, plus today's capacity when the requested month is the current one.
     * </summary>
     *
     * @param string $month Target month in YYYY-MM format
     * @return array{month: string, users: array, capacity_today: array|null} Month payload
     */
    public function getPlanningMonth(string $month): array
    {
        [$year, $monthNum] = $this->parseMonth($month);
        $monthStart = Carbon::create($year, $monthNum, 1);
        $monthEnd   = (clone $monthStart)->endOfMonth();

        $users = User::query()
            ->with(['department', 'absences' => function ($q) use ($monthStart, $monthEnd) {
                $q->where('start_date', '<=', $monthEnd)->where('end_date', '>=', $monthStart);
            }])
            ->orderBy('lastname')
            ->get();

        $todayStr = Carbon::today()->toDateString();
        $isCurrentMonth = Carbon::now()->year === $year && Carbon::now()->month === $monthNum;

        return [
            'month'         => $month,
            'users'         => $users->map(fn(User $u) => $this->formatUser($u))->all(),
            'capacity_today' => $isCurrentMonth ? $this->capacityToday($todayStr) : null,
        ];
    }

    private function formatUser(User $u): array
    {
        return [
            'id'         => (string) $u->id,
            'firstname'  => $u->firstname ?? '',
            'lastname'   => $u->lastname ?? '',
            'title'      => $u->title ?? '',
            'department' => $u->department ? ['id' => $u->department->id, 'name' => $u->department->name] : null,
            'status'     => $u->status,
            'absences'   => $u->absences->map(fn(Absence $a) => [
                'id'         => $a->id,
                'type'       => $a->type?->value,
                'start_date' => $a->start_date?->toDateString(),
                'start_half' => 0,
                'end_date'   => $a->end_date?->toDateString(),
                'end_half'   => 1,
                'reason'     => $a->reason,
            ])->values()->all(),
        ];
    }

    private function capacityToday(string $today): array
    {
        $total   = User::query()->count();
        $onLeave = User::query()->whereHas('absences', fn($q) => $q
            ->where('start_date', '<=', $today)->where('end_date', '>=', $today))->count();
        return ['available' => $total - $onLeave, 'on_leave' => $onLeave, 'total' => $total];
    }

    /* ─────────────────────── Apply scenario ─────────────────────── */

    /**
     * <summary>
     *  Persist a batch of simulated absences as real Absence rows. Single-entity bulk insert —
     *  the caller (Manager) owns the surrounding transaction and any recalculation dispatch.
     * </summary>
     *
     * @param array<int, array<string, mixed>> $absences Validated absence payloads (user_id, start_date, end_date, type?, reason?)
     * @return array<int, int> Ids of the created absences
     */
    public function applyPlanning(array $absences): array
    {
        $ids = [];
        foreach ($absences as $a) {
            $abs = Absence::create([
                'user_id' => (int) $a['user_id'],
                'start_date' => $a['start_date'],
                'end_date' => $a['end_date'],
                'type' => $a['type'] ?? 'planned',
                'reason' => $a['reason'] ?? null,
            ]);
            $ids[] = $abs->id;
        }
        return $ids;
    }

    /* ─────────────────────── helpers ─────────────────────── */

    private function parseMonth(string $month): array
    {
        [$y, $m] = explode('-', $month);
        return [(int) $y, (int) $m];
    }
}
