<?php

namespace App\Managers;

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
     *  Create a new Absence for a user.
     *  TODO: dispatch project risk recalculation job for projects the user belongs to
     *        when the absence range overlaps "today" (so dashboards reflect it immediately).
     * </summary>
     *
     * @param User $user Target user the absence belongs to
     * @param array<string, mixed> $data Validated payload: start_date, end_date, type?, reason?
     * @return Absence Newly created absence
     */
    public function createAbsenceForUser(User $user, array $data): Absence
    {
        return $this->absenceService->createAbsenceForUser($user, $data);
    }

    /**
     * <summary>
     *  Update an existing Absence.
     *  TODO: when start_date/end_date changes such that the "active today" status flips,
     *        dispatch project risk recalculation for the user's projects.
     * </summary>
     *
     * @param Absence $absence Target absence
     * @param array<string, mixed> $data Validated payload (all fields optional)
     * @return Absence Refreshed absence
     */
    public function updateAbsence(Absence $absence, array $data): Absence
    {
        return $this->absenceService->updateAbsence($absence, $data);
    }

    /**
     * <summary>
     *  Soft-delete an Absence.
     *  TODO: dispatch project risk recalculation for the user's projects when the absence
     *        was active today (its removal restores coverage).
     * </summary>
     *
     * @param Absence $absence Target absence to soft-delete
     * @return void
     */
    public function deleteAbsence(Absence $absence): void
    {
        $this->absenceService->deleteAbsence($absence);
    }
}
