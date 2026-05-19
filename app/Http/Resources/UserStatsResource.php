<?php

namespace App\Http\Resources;

use App\Support\MetricPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $r           = $this->resource;
        $criticality = $r['criticality'];
        $busFactor   = $r['bus_factor_in_org'];
        $skills      = $r['skills'];
        $projects    = $r['active_projects'];
        $catCount    = count($skills['by_category']);

        return [
            'criticality' => MetricPresenter::criticality((int) $criticality['score']),
            'bus_factor_in_org' => MetricPresenter::count(
                n:         (int) $busFactor['count'],
                severity:  $busFactor['count'] > 0 ? 'critical' : 'ok',
                zeroLabel: 'Safe',
                hint:      $busFactor['count'] > 0 ? 'Projects at risk' : 'No single-point exposure',
            ),
            'skills' => MetricPresenter::count(
                n:         (int) $skills['total'],
                severity:  'ok',
                zeroLabel: 'None',
                hint:      $catCount > 0 ? "{$catCount} categories" : null,
            ),
            'active_projects' => MetricPresenter::count(
                n:         (int) $projects['count'],
                severity:  'ok',
                zeroLabel: 'None',
                hint:      $projects['count'] > 0 ? 'Assigned' : null,
            ),
            'breakdown' => [
                'criticality_detail'   => [
                    'unique_skills'       => $criticality['unique_skills'],
                    'silo_count'          => $criticality['silo_count'],
                    'bus_factor_projects' => $criticality['bus_factor_projects'],
                ],
                'bus_factor_projects'  => $busFactor['projects'],
                'skills_by_category'   => $skills['by_category'],
                'active_projects_list' => $projects['projects'],
            ],
        ];
    }
}
