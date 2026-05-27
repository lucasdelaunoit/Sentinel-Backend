<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\User;
use App\Support\QueryParams;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class AbsenceService
{
    /**
     * <summary>
     *  Retrieve paginated, filterable, sortable list of absences for a specific user.
     *  Filters: type, search (reason). Sorts: start_date, end_date, type.
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter & sort parameters
     * @param User $user Target user
     * @return LengthAwarePaginator Paginated absences for the user
     */
    public function getAgileAbsencesForUser(QueryParams $params, User $user): LengthAwarePaginator
    {
        return QueryBuilder::for($user->absences()->getQuery(), $params->toRequest())
            ->allowedFilters([
                AllowedFilter::exact('type'),
                AllowedFilter::callback('search', fn($q, $v) => $q->where('reason', 'like', "%{$v}%")),
            ])
            ->allowedSorts([
                AllowedSort::field('start_date'),
                AllowedSort::field('end_date'),
                AllowedSort::field('type'),
            ])
            ->defaultSort('-start_date')
            ->paginate($params->perPage())
            ->appends($params->rawQuery());
    }

    /**
     * <summary>
     *  Upcoming absences whose window opens between today and today + horizon (inclusive),
     *  ordered by start date. Eager-loads the absent user and the data each project's coverage
     *  matrix needs (skill requirements + teammates' skills & absences) to avoid N+1 downstream.
     *  No status filter — the absences table has no approval column.
     * </summary>
     *
     * @param int $horizonDays Forward window in days
     * @return Collection<int, Absence> Upcoming absences with user + projects eager-loaded
     */
    public function getUpcomingAbsences(int $horizonDays): Collection
    {
        $today = Carbon::today();

        return Absence::query()
            ->with([
                'user.projects.skillRequirements',
                'user.projects.users.skills',
                'user.projects.users.absences',
            ])
            ->whereDate('start_date', '>=', $today)
            ->whereDate('start_date', '<=', (clone $today)->addDays($horizonDays))
            ->orderBy('start_date')
            ->get();
    }

    /**
     * <summary>
     *  Persist a new Absence attached to the given user.
     * </summary>
     *
     * @param User $user Target user the absence belongs to
     * @param array<string, mixed> $data Validated payload: start_date, end_date, type?, reason?
     * @return Absence Newly created absence
     */
    public function createAbsenceForUser(User $user, array $data): Absence
    {
        return $user->absences()->create($data);
    }

    /**
     * <summary>
     *  Update an Absence row and return the refreshed model.
     * </summary>
     *
     * @param Absence $absence Target absence
     * @param array<string, mixed> $data Validated payload (all fields optional)
     * @return Absence Refreshed absence
     */
    public function updateAbsence(Absence $absence, array $data): Absence
    {
        $absence->update($data);

        return $absence->fresh();
    }

    /**
     * <summary>
     *  Soft-delete a single Absence row.
     * </summary>
     *
     * @param Absence $absence Target absence to soft-delete
     * @return void
     */
    public function deleteAbsence(Absence $absence): void
    {
        $absence->delete();
    }
}
