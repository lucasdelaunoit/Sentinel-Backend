<?php

namespace App\Managers;

use App\DTO\Stats\UserAbsenceStats;
use App\Jobs\RecalculateProjectRiskJob;
use App\Models\Absence;
use App\Models\User;
use App\Services\AbsenceService;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AbsenceManager
{
    public function __construct(
        private readonly AbsenceService $absenceService,
    ) {}

    /**
     * <summary>
     *  Retrieve paginated, filterable, sortable list of absences for a specific user.
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter & sort parameters
     * @param User $user Target user
     * @return LengthAwarePaginator Paginated absences for the user
     */
    public function getAgileAbsencesForUser(QueryParams $params, User $user): LengthAwarePaginator
    {
        return $this->absenceService->getAgileAbsencesForUser($params, $user);
    }

    /**
     * <summary>
     *  Create a new Absence for a user and dispatch risk recalc for the user's projects.
     * </summary>
     *
     * @param User $user Target user the absence belongs to
     * @param array<string, mixed> $data Validated payload: start_date, end_date, type?, reason?
     * @return Absence Newly created absence
     */
    public function createAbsenceForUser(User $user, array $data): Absence
    {
        $absence = $this->absenceService->createAbsenceForUser($user, $data);
        $this->dispatchProjectRecalculations($user);
        return $absence;
    }

    /**
     * <summary>
     *  Update an existing Absence and dispatch risk recalc for the user's projects.
     * </summary>
     *
     * @param Absence $absence Target absence
     * @param array<string, mixed> $data Validated payload (all fields optional)
     * @return Absence Refreshed absence
     */
    public function updateAbsence(Absence $absence, array $data): Absence
    {
        $fresh = $this->absenceService->updateAbsence($absence, $data);
        $this->dispatchProjectRecalculations($fresh->user);
        return $fresh;
    }

    /**
     * <summary>
     *  Soft-delete an Absence and dispatch risk recalc for the owning user's projects.
     * </summary>
     *
     * @param Absence $absence Target absence to soft-delete
     * @return void
     */
    public function deleteAbsence(Absence $absence): void
    {
        $user = $absence->user;
        $this->absenceService->deleteAbsence($absence);
        if ($user) $this->dispatchProjectRecalculations($user);
    }

    /**
     * <summary>
     *  Assemble the typed UserAbsenceStats DTO for GET /users/{user}/absences/stats.
     *  Orchestrates AbsenceService — one Service call per metric.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return UserAbsenceStats total_absences, days_off, upcoming
     */
    public function getUserAbsenceStats(User $user): UserAbsenceStats
    {
        return new UserAbsenceStats(
            totalAbsences: $this->absenceService->getUserTotalAbsencesStat($user),
            daysOff: $this->absenceService->getUserDaysOffThisYearStat($user),
            upcoming: $this->absenceService->getUserUpcomingAbsencesStat($user),
        );
    }

    private function dispatchProjectRecalculations(?User $user): void
    {
        if ($user === null) return;
        $user->loadMissing('projects');
        foreach ($user->projects as $project) {
            RecalculateProjectRiskJob::dispatch($project);
        }
    }
}
