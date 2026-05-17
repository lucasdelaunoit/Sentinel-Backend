<?php

namespace App\Managers;

use App\Models\CompanyHoliday;
use App\Services\CompanyHolidayService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompanyHolidayManager
{
    public function __construct(
        private readonly CompanyHolidayService $companyHolidayService,
    ) {}

    /**
     * <summary>
     *  Return all CompanyHoliday rows ordered by date.
     * </summary>
     *
     * @return Collection<int, CompanyHoliday>
     */
    public function getAgileCompanyHolidays(): Collection
    {
        return $this->companyHolidayService->getAgileCompanyHolidays();
    }

    /**
     * <summary>
     *  Return all CompanyHoliday rows applicable to the given month (including recurring).
     * </summary>
     *
     * @param int $year  Full year
     * @param int $month Month 1–12
     * @return Collection<int, CompanyHoliday>
     */
    public function getCompanyHolidaysForMonth(int $year, int $month): Collection
    {
        return $this->companyHolidayService->getCompanyHolidaysForMonth($year, $month);
    }

    /**
     * <summary>
     *  Create a CompanyHoliday inside a transaction.
     * </summary>
     *
     * @param array $data Validated payload
     * @return CompanyHoliday Created holiday
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function createCompanyHoliday(array $data): CompanyHoliday
    {
        return DB::transaction(fn() => $this->companyHolidayService->createCompanyHoliday($data));
    }

    /**
     * <summary>
     *  Update a CompanyHoliday inside a transaction.
     * </summary>
     *
     * @param CompanyHoliday $holiday Target holiday
     * @param array $data Validated payload
     * @return CompanyHoliday Freshly reloaded holiday
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function updateCompanyHoliday(CompanyHoliday $holiday, array $data): CompanyHoliday
    {
        return DB::transaction(fn() => $this->companyHolidayService->updateCompanyHoliday($holiday, $data));
    }

    /**
     * <summary>
     *  Hard-delete a CompanyHoliday inside a transaction.
     * </summary>
     *
     * @param CompanyHoliday $holiday Target holiday
     * @return void
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function deleteCompanyHoliday(CompanyHoliday $holiday): void
    {
        DB::transaction(fn() => $this->companyHolidayService->deleteCompanyHoliday($holiday));
    }
}
