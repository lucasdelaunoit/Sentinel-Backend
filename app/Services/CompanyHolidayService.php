<?php

namespace App\Services;

use App\Models\CompanyHoliday;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CompanyHolidayService
{
    /**
     * <summary>
     *  Return paginated CompanyHoliday rows ordered by date ascending.
     * </summary>
     *
     * @param QueryParams $params Normalized query params
     * @return LengthAwarePaginator
     */
    public function getAgileCompanyHolidays(QueryParams $params): LengthAwarePaginator
    {
        return QueryBuilder::for(CompanyHoliday::class, $params->toRequest())
            ->allowedFilters([
                AllowedFilter::callback('search', fn($q, $v) => $q->where('name', 'like', "%{$v}%")),
                AllowedFilter::exact('recurring'),
            ])
            ->allowedSorts(['name', 'start_date', 'end_date'])
            ->defaultSort('start_date')
            ->paginate($params->perPage())
            ->appends($params->rawQuery());
    }

    /**
     * <summary>
     *  Return all CompanyHoliday rows whose date falls in the given year/month, or which are recurring on that month.
     * </summary>
     *
     * @param int $year  Full year (e.g. 2026)
     * @param int $month Month 1–12
     * @return Collection<int, CompanyHoliday>
     */
    public function getCompanyHolidaysForMonth(int $year, int $month): Collection
    {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd   = date('Y-m-t', strtotime($monthStart));

        return CompanyHoliday::query()
            ->where(function ($q) use ($monthStart, $monthEnd) {
                // Non-recurring: range intersects month
                $q->where('recurring', false)
                    ->where('start_date', '<=', $monthEnd)
                    ->where('end_date', '>=', $monthStart);
            })
            ->orWhere(function ($q) use ($month) {
                // Recurring: any range that touches this month-of-year (handles spans across months)
                $q->where('recurring', true)
                    ->where(function ($qq) use ($month) {
                        $qq->whereMonth('start_date', $month)
                            ->orWhereMonth('end_date', $month)
                            ->orWhere(function ($qqq) use ($month) {
                                $qqq->whereMonth('start_date', '<=', $month)
                                    ->whereMonth('end_date', '>=', $month);
                            });
                    });
            })
            ->orderBy('start_date')
            ->get();
    }

    /**
     * <summary>
     *  Persist a new CompanyHoliday row.
     * </summary>
     *
     * @param array $data Validated payload — name, start_date, end_date, recurring
     * @return CompanyHoliday Created holiday
     */
    public function createCompanyHoliday(array $data): CompanyHoliday
    {
        return CompanyHoliday::create($data);
    }

    /**
     * <summary>
     *  Update a single CompanyHoliday row.
     * </summary>
     *
     * @param CompanyHoliday $holiday Target holiday
     * @param array $data Validated payload
     * @return CompanyHoliday Freshly reloaded holiday
     */
    public function updateCompanyHoliday(CompanyHoliday $holiday, array $data): CompanyHoliday
    {
        $holiday->update($data);

        return $holiday->fresh();
    }

    /**
     * <summary>
     *  Hard-delete a single CompanyHoliday row.
     * </summary>
     *
     * @param CompanyHoliday $holiday Target holiday
     * @return void
     */
    public function deleteCompanyHoliday(CompanyHoliday $holiday): void
    {
        $holiday->delete();
    }
}
