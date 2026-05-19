<?php

namespace App\Http\Resources;

use App\Support\StatCard;
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

        return [
            'criticality' => StatCard::criticality((int) $criticality['score']),
            'bus_factor_in_org' => StatCard::count(
                n:        (int) $busFactor['count'],
                singular: 'project at risk',
                plural:   'projects at risk',
                severity: $busFactor['count'] > 0 ? 'critical' : 'ok',
                hint:     'User pushes bus factor to threshold',
            ),
            'skills' => StatCard::count(
                n:        (int) $skills['total'],
                singular: 'skill',
                plural:   'skills',
                severity: 'ok',
                hint:     'Across ' . count($skills['by_category']) . ' categories',
            ),
            'active_projects' => StatCard::count(
                n:        (int) $projects['count'],
                singular: 'active project',
                plural:   'active projects',
                severity: 'ok',
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
