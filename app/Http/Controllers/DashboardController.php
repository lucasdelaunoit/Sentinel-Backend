<?php

namespace App\Http\Controllers;

use App\Http\Resources\DashboardStatsResource;
use App\Http\Resources\KnowledgeCoverageResource;
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

    /**
     * <summary>
     *  Competency-radar data — per-skill-category knowledge-coverage breakdown across active projects.
     * </summary>
     *
     * @return KnowledgeCoverageResource categories[] (one per skill category) + most_fragile
     */
    public function getKnowledgeCoverage(): KnowledgeCoverageResource
    {
        // Act (Manager)
        $breakdown = $this->dashboardManager->getKnowledgeCoverage();

        // Return (Controller)
        return new KnowledgeCoverageResource($breakdown);
    }

    // TODO : Maybe later
    public function projectsAtRiskDetail(): JsonResponse
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
