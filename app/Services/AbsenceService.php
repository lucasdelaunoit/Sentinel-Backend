<?php

namespace App\Services;

use App\Enums\AbsenceHalf;
use App\Metrics\Severity;
use App\Metrics\Stat;
use App\Models\Absence;
use App\Models\Project;
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
                AllowedFilter::callback(
                    'upcoming',
                    fn($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN)
                        ? $q->whereDate('end_date', '>=', Carbon::today())
                        : $q,
                ),
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
     *  User IDs among a project's team who are absent at any point inside the horizon window
     *  [today, today + horizonDays]. An absence overlaps when it starts on/before the horizon end
     *  and ends on/after today. Used by FragilityCalculator to project absence impact.
     * </summary>
     *
     * @param Project $project Target project whose team is checked
     * @param int $horizonDays Forward window in days
     * @return array<int> Distinct user IDs absent within the horizon
     */
    public function getHorizonAbsentUserIdsForProject(Project $project, int $horizonDays): array
    {
        $today = Carbon::today()->toDateString();
        $horizonEnd = Carbon::today()->addDays($horizonDays)->toDateString();

        return $project->users()
            ->whereHas('absences', fn($q) => $q
                ->whereDate('start_date', '<=', $horizonEnd)
                ->whereDate('end_date', '>=', $today)
            )
            ->pluck('users.id')
            ->all();
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
     *  clamped to [Jan 1, Dec 31] of the current year. Half-day boundaries deduct 0.5 each
     *  (afternoon start / morning end) when the real boundary falls inside the clamped window.
     * </summary>
     *
     * @param User $user Target user
     * @return Stat
     */
    public function getUserDaysOffThisYearStat(User $user): Stat
    {
        $yearStart = Carbon::now()->startOfYear();
        $yearEnd = Carbon::now()->endOfYear();

        $absences = $user->absences()
            ->whereDate('start_date', '<=', $yearEnd->toDateString())
            ->whereDate('end_date', '>=', $yearStart->toDateString())
            ->get(['start_date', 'end_date', 'start_half', 'end_half']);

        $days = 0.0;
        foreach ($absences as $absence) {
            $rawStart = Carbon::parse($absence->start_date);
            $rawEnd = Carbon::parse($absence->end_date);
            $start = $rawStart->copy()->max($yearStart);
            $end = $rawEnd->copy()->min($yearEnd);

            $count = $start->diffInDays($end) + 1;
            if ($rawStart->gte($yearStart) && $absence->start_half === AbsenceHalf::Afternoon) {
                $count -= 0.5;
            }
            if ($rawEnd->lte($yearEnd) && $absence->end_half === AbsenceHalf::Morning) {
                $count -= 0.5;
            }

            $days += max(0.5, $count);
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
