<?php

namespace App\Http\Resources;

use App\Support\MetricPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsersStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $r       = $this->resource;
        $total   = (int) $r['total'];
        $avail   = (int) $r['available'];
        $crit    = (int) $r['critical_users']['count'];
        $unique  = (int) $r['unique_skill_holders'];

        return [
            'total' => MetricPresenter::make(
                value:    "{$total} " . ($total === 1 ? 'user' : 'users'),
                severity: 'ok',
                raw:      $total,
                hint:     'Headcount',
            ),
            'available' => MetricPresenter::make(
                value:    "{$avail} available",
                severity: $r['away'] > 0 ? 'warning' : 'ok',
                raw:      $avail,
                hint:     $r['away'] > 0 ? "{$r['away']} away today" : 'All present',
            ),
            'critical_users' => MetricPresenter::make(
                value:    "{$crit} at-risk",
                severity: $crit > 0 ? 'critical' : 'ok',
                raw:      $crit,
                hint:     'Criticality ≥ 50',
            ),
            'unique_skill_holders' => MetricPresenter::make(
                value:    "{$unique} " . ($unique === 1 ? 'sole holder' : 'sole holders'),
                severity: $unique > 0 ? 'warning' : 'ok',
                raw:      $unique,
                hint:     'Skill held by one user',
            ),
            'critical_users_preview' => $r['critical_users']['users'],
        ];
    }
}
