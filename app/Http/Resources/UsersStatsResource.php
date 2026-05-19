<?php

namespace App\Http\Resources;

use App\Support\StatCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsersStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'total' => StatCard::count(
                n:        (int) $r['total'],
                severity: 'ok',
                hint:     'Headcount',
            ),
            'available' => StatCard::count(
                n:         (int) $r['available'],
                severity:  $r['away'] > 0 ? 'warning' : 'ok',
                zeroLabel: 'None',
                hint:      $r['away'] > 0 ? "{$r['away']} away today" : 'All present',
            ),
            'critical_users' => StatCard::count(
                n:         (int) $r['critical_users']['count'],
                severity:  $r['critical_users']['count'] > 0 ? 'critical' : 'ok',
                zeroLabel: 'None',
                hint:      'Criticality ≥ 50',
            ),
            'unique_skill_holders' => StatCard::count(
                n:         (int) $r['unique_skill_holders'],
                severity:  $r['unique_skill_holders'] > 0 ? 'warning' : 'ok',
                zeroLabel: 'None',
                hint:      'Sole holders',
            ),
            'departments' => StatCard::label(
                value:    (string) $r['departments']['value'],
                severity: (string) $r['departments']['severity'],
                hint:     $r['departments']['insight'] ?? null,
            ),
            'critical_users_preview' => $r['critical_users']['users'],
        ];
    }
}
