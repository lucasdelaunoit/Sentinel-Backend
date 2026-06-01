<?php

namespace App\Services;

use App\Metrics\Severity;
use App\Metrics\Stat;
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

    /**
     * <summary>
     *  Build the all-time total-absences Stat for a user — live count via absences relation.
     * </summary>
     *
     * @param User $user Target user
     * @return Stat
     */
    public function getUserTotalAbsencesStat(User $user): Stat
    {
        $count = $user->absences()->count();

        return new Stat(
            value: (string) $count,
            valueRaw: $count,
            severity: Severity::OK,
            insight: 'all-time',
        );
    }

    /**
     * <summary>
     *  Build the days-off-this-year Stat for a user. Sums inclusive day counts of every absence
     *  clamped to [Jan 1, Dec 31] of the current year. Single-day absence counts as 1 day.
     * </summary>
     *
     * @param User $user Target user
     * @return Stat
     */
    public function getUserDaysOffThisYearStat(User $user): Stat
    {
        $yearStart = Carbon::now()->startOfYear()->toDateString();
        $yearEnd = Carbon::now()->endOfYear()->toDateString();

        $absences = $user->absences()
            ->whereDate('start_date', '<=', $yearEnd)
            ->whereDate('end_date', '>=', $yearStart)
            ->get(['start_date', 'end_date']);

        $days = 0;
        foreach ($absences as $absence) {
            $start = Carbon::parse($absence->start_date)->max(Carbon::parse($yearStart));
            $end = Carbon::parse($absence->end_date)->min(Carbon::parse($yearEnd));
            $days += $start->diffInDays($end) + 1;
        }

        return new Stat(
            value: (string) $days,
            valueRaw: $days,
            severity: Severity::OK,
            insight: 'year-to-date',
        );
    }

    /**
     * <summary>
     *  Build the upcoming-absences Stat for a user. Counts absences whose start_date is after today.
     *  Insight is the next start date formatted as "next: 01 Jun 2026", or "None" when empty.
     * </summary>
     *
     * @param User $user Target user
     * @return Stat
     */
    public function getUserUpcomingAbsencesStat(User $user): Stat
    {
        $today = Carbon::today()->toDateString();

        $upcoming = $user->absences()
            ->whereDate('start_date', '>', $today)
            ->orderBy('start_date')
            ->get(['start_date']);

        $count = $upcoming->count();
        $next = $count > 0 ? Carbon::parse($upcoming->first()->start_date)->format('d M Y') : null;

        return new Stat(
            value: (string) $count,
            valueRaw: $count,
            severity: $count > 0 ? Severity::WARNING : Severity::OK,
            insight: $next !== null ? "next: {$next}" : 'None',
        );
    }
}
