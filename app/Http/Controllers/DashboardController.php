<?php

namespace App\Http\Controllers;

use App\Http\Resources\DashboardStatsResource;
use App\Managers\DashboardManager;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardManager $dashboardManager,
    ) {}

    /**
     * <summary>
     *  Dashboard headline stats — fragile_projects, knowledge_coverage, team_availability, absence_impact.
     * </summary>
     *
     * @return DashboardStatsResource Typed DashboardStats DTO serialized as 4 Stat blocks
     */
    public function getDashboardStats(): DashboardStatsResource
    {
        // Act (Manager)
        $stats = $this->dashboardManager->getTodayStats();

        // Return (Controller)
        return new DashboardStatsResource($stats);
    }

    // TODO : Maybe later
    public function projectsAtRiskDetail(): JsonResponse
    {
        return response()->json([], 200);
    }

    public function knowledgeCoverageDetail(): JsonResponse
    {
        return response()->json([], 200);
    }

    public function teamAvailabilityDetail(): JsonResponse
    {
        return response()->json([], 200);
    }

    public function absenceImpactDetail(): JsonResponse
    {
        return response()->json([], 200);
    }
}
