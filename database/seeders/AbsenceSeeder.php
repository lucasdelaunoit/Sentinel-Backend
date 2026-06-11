<?php

namespace Database\Seeders;

use App\Enums\AbsenceHalf;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Absence scenarios anchored to "next Monday" so engineered overlaps always land on
 * working days, whatever day you re-seed:
 *
 *  - Hannah is out TODAY → dashboard shows a live absence (Python stays safe: Rajesh + Diego).
 *  - Thomas off Mon-Fri next week → Mobile App loses its only React/TS owner inside the
 *    14-day horizon → upcoming risk event.
 *  - Karim (conference Wed-Fri) overlaps Thomas, Petra overlaps Karim Thu-Fri → 3 people
 *    absent simultaneously → planning hotspot, Infrastructure Migration craters.
 *  - Julie half-day → exercises the AM/PM half-day rendering.
 *  - Past + far-future entries give history and a populated next-month planning view.
 */
class AbsenceSeeder extends Seeder
{
    public function run(): void
    {
        $users  = User::all()->keyBy('email');
        $monday = Carbon::today()->next(Carbon::MONDAY);

        $scenarios = [
            // ── Live: out today ──
            ['hannah.vermeulen@qite.be', Carbon::today()->subDay(), Carbon::today()->addDay(), AbsenceType::Vacation, 'Long weekend in the Ardennes'],

            // ── Next week: the engineered overlap ──
            ['thomas.janssens@qite.be', $monday->copy(), $monday->copy()->addDays(4), AbsenceType::Vacation, 'Annual leave'],
            ['karim.benali@qite.be', $monday->copy()->addDays(2), $monday->copy()->addDays(4), AbsenceType::Conference, 'KubeCon Europe'],
            ['petra.novak@qite.be', $monday->copy()->addDays(3), $monday->copy()->addDays(8), AbsenceType::Vacation, null],
            // Jonas = last owner of Legacy ERP's PHP/MySQL — his leave inside the horizon
            // stacks absence impact on a project already missing Java/Spring Boot/Angular.
            ['jonas.peeters@qite.be', $monday->copy()->addDays(7), $monday->copy()->addDays(18), AbsenceType::Parental, 'Parental leave'],

            // ── Half-day (same-day PM) ──
            ['julie.lambert@qite.be', $monday->copy()->addDay(), $monday->copy()->addDay(), AbsenceType::Other, 'Doctor appointment', AbsenceHalf::Afternoon, AbsenceHalf::Afternoon],

            // ── Past month: history ──
            ['emma.claes@qite.be', Carbon::today()->subDays(21), Carbon::today()->subDays(17), AbsenceType::Vacation, null],
            ['rajesh.iyer@qite.be', Carbon::today()->subDays(12), Carbon::today()->subDays(10), AbsenceType::Training, 'Advanced PostgreSQL workshop'],
            ['bram.wouters@qite.be', Carbon::today()->subDays(5), Carbon::today()->subDays(4), AbsenceType::Other, 'Sick leave'],

            // ── Further out: populates next month's planning ──
            ['diego.fernandez@qite.be', $monday->copy()->addDays(14), $monday->copy()->addDays(16), AbsenceType::Training, 'MLOps certification'],
            ['sofia.moreau@qite.be', $monday->copy()->addDays(21), $monday->copy()->addDays(30), AbsenceType::Vacation, 'Summer holidays'],
            ['lucasdelaunoit@qite.be', $monday->copy()->addDays(28), $monday->copy()->addDays(32), AbsenceType::Vacation, null],
        ];

        foreach ($scenarios as $row) {
            [$email, $start, $end, $type, $reason] = $row;

            Absence::factory()->create([
                'user_id'    => $users[$email]->id,
                'start_date' => $start,
                'end_date'   => $end,
                'start_half' => $row[5] ?? AbsenceHalf::Morning,
                'end_half'   => $row[6] ?? AbsenceHalf::Afternoon,
                'type'       => $type,
                'reason'     => $reason,
            ]);
        }
    }
}
