<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\CompanyHoliday;
use App\Models\OrganizationSetting;
use App\Support\AbsenceSlot;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves the two day-counts an absence carries:
 *   - total_days      : raw calendar span (half-aware), weekends/holidays INCLUDED.
 *   - normalized_days : working days only (closed weekdays + company holidays REMOVED).
 *
 * Normalized count follows the HYBRID policy (an HR-correctness decision):
 *   - while the absence is UPCOMING (start_date > today) it is recomputed LIVE from the
 *     current calendar, so editing the working week / holidays before the leave is taken
 *     re-counts it;
 *   - once the absence HAS STARTED (start_date <= today) the value is FROZEN on first read
 *     (snapshot persisted + normalized_frozen_at stamped) and later calendar edits never
 *     change that record.
 *
 * Registered as a singleton so settings + holidays are loaded once per request and reused
 * across every row of a paginated collection (no N+1).
 */
class AbsenceNormalizer
{
    private ?OrganizationSetting $setting = null;

    /** @var Collection<int, CompanyHoliday>|null */
    private ?Collection $holidays = null;

    public function __construct(
        private readonly CalendarService $calendar,
        private readonly OrganizationSettingService $settings,
    ) {}

    /**
     * <summary>Raw calendar span in days (half-aware) — weekends & holidays included.</summary>
     *
     * @param Absence $absence Target absence
     * @return float Day count in 0.5 steps
     */
    public function totalDays(Absence $absence): float
    {
        $start = AbsenceSlot::start($absence->start_date, $absence->start_half);
        $end = AbsenceSlot::end($absence->end_date, $absence->end_half);

        return max(0.0, ($end - $start + 1) / 2);
    }

    /**
     * <summary>
     *  Hybrid working-day count: live while upcoming, frozen snapshot once started.
     * </summary>
     *
     * @param Absence $absence Target absence
     * @return float Working-day count in 0.5 steps
     */
    public function resolve(Absence $absence): float
    {
        $hasStarted = Carbon::parse($absence->start_date)->lte(Carbon::today());

        if ($hasStarted) {
            // Already frozen → settled deal, return the snapshot untouched.
            if ($absence->normalized_frozen_at !== null && $absence->normalized_days !== null) {
                return (float) $absence->normalized_days;
            }

            // First read on/after start → freeze the snapshot now (one-time write).
            $value = $this->compute($absence);
            $absence->timestamps = false;
            $absence->forceFill([
                'normalized_days' => $value,
                'normalized_frozen_at' => Carbon::now(),
            ])->saveQuietly();
            $absence->timestamps = true;

            return $value;
        }

        // Upcoming → always reflect the current calendar.
        return $this->compute($absence);
    }

    private function compute(Absence $absence): float
    {
        return $this->calendar->countWorkingHalfDays(
            $absence->start_date,
            $absence->start_half,
            $absence->end_date,
            $absence->end_half,
            $this->setting(),
            $this->holidays(),
        );
    }

    private function setting(): OrganizationSetting
    {
        return $this->setting ??= $this->settings->getOrganizationSetting();
    }

    /** @return Collection<int, CompanyHoliday> */
    private function holidays(): Collection
    {
        return $this->holidays ??= CompanyHoliday::all();
    }
}
