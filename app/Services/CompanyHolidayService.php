<?php

namespace App\Services;

use App\Models\CompanyHoliday;
use Illuminate\Database\Eloquent\Collection;

class CompanyHolidayService
{
    /**
     * <summary>
     *  Return all CompanyHoliday rows ordered by date ascending.
     * </summary>
     *
     * @return Collection<int, CompanyHoliday>
     */
    public function getAgileCompanyHolidays(): Collection
    {
        return CompanyHoliday::orderBy('date')->get();
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
        return CompanyHoliday::query()
            ->where(function ($q) use ($year, $month) {
                $q->whereYear('date', $year)->whereMonth('date', $month);
            })
            ->orWhere(function ($q) use ($month) {
                $q->where('recurring', true)->whereMonth('date', $month);
            })
            ->orderBy('date')
            ->get();
    }

    /**
     * <summary>
     *  Persist a new CompanyHoliday row.
     * </summary>
     *
     * @param array $data Validated payload — name, date, recurring
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
