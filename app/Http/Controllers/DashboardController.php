<?php

namespace App\Http\Controllers;

use App\Http\Resources\DashboardStatsResource;
use App\Managers\DashboardManager;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardManager $dashboardManager
    ) {}

    public function stats(): DashboardStatsResource
    {
        return new DashboardStatsResource($this->dashboardManager->getTodayStats());
    }

    public function projectsAtRiskDetail(): JsonResponse
    {
        return response()->json($this->dashboardManager->getProjectsAtRiskDetail());
    }

    public function knowledgeCoverageDetail(): JsonResponse
    {
        return response()->json($this->dashboardManager->getKnowledgeCoverageDetail());
    }

    public function teamAvailabilityDetail(): JsonResponse
    {
        return response()->json($this->dashboardManager->getTeamAvailabilityDetail());
    }

    public function absenceImpactDetail(): JsonResponse
    {
        return response()->json($this->dashboardManager->getAbsenceImpactDetail());
    }
}
