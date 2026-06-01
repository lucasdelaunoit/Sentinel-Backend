<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAbsenceRequest;
use App\Http\Requests\UpdateAbsenceRequest;
use App\Http\Resources\AbsenceResource;
use App\Http\Resources\UserAbsenceStatsResource;
use App\Managers\AbsenceManager;
use App\Models\Absence;
use App\Models\User;
use App\Support\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AbsenceController extends Controller
{
    public function __construct(
        private readonly AbsenceManager $absenceManager,
    ) {}

    /**
     * <summary>
     *  Retrieve paginated, filterable, sortable absences for a specific user.
     * </summary>
     *
     * @param Request $request Pagination, filter (type, search), sort parameters
     * @param User $user Route-model bound user
     * @return AnonymousResourceCollection Paginated absences
     */
    public function getAgileAbsencesForUser(Request $request, User $user): AnonymousResourceCollection
    {
        // Act (Manager)
        $absences = $this->absenceManager->getAgileAbsencesForUser(QueryParams::fromRequest($request), $user);

        // Return (Controller)
        return AbsenceResource::collection($absences);
    }

    /**
     * <summary>
     *  Create a new Absence for a user.
     * </summary>
     *
     * @param StoreAbsenceRequest $request start_date, end_date, type?, reason?
     * @param User $user Route-model bound user
     * @return JsonResponse Created absence — HTTP 201
     */
    public function createAbsenceForUser(StoreAbsenceRequest $request, User $user): JsonResponse
    {
        // Act (Manager)
        $absence = $this->absenceManager->createAbsenceForUser($user, $request->validated());

        // Return (Controller)
        return AbsenceResource::make($absence)->response()->setStatusCode(201);
    }

    /**
     * <summary>
     *  Update an existing Absence.
     * </summary>
     *
     * @param UpdateAbsenceRequest $request Fields to update (all optional)
     * @param Absence $absence Route-model bound absence
     * @return AbsenceResource Updated absence
     */
    public function updateAbsence(UpdateAbsenceRequest $request, Absence $absence): AbsenceResource
    {
        // Act (Manager)
        $absence = $this->absenceManager->updateAbsence($absence, $request->validated());

        // Return (Controller)
        return AbsenceResource::make($absence);
    }

    /**
     * <summary>
     *  Soft-delete an Absence.
     * </summary>
     *
     * @param Absence $absence Route-model bound absence
     * @return JsonResponse HTTP 204 No Content
     */
    public function deleteAbsence(Absence $absence): JsonResponse
    {
        // Act (Manager)
        $this->absenceManager->deleteAbsence($absence);

        // Return (Controller)
        return response()->json(null, 204);
    }

    /**
     * <summary>
     *  Get absence stats for a specific user: total absences, days off this year, upcoming.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return UserAbsenceStatsResource total_absences, days_off, upcoming
     */
    public function getUserAbsenceStats(User $user): UserAbsenceStatsResource
    {
        // Act (Manager)
        $stats = $this->absenceManager->getUserAbsenceStats($user);

        // Return (Controller)
        return new UserAbsenceStatsResource($stats);
    }
}
