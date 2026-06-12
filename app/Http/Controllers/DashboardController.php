<?php

namespace App\Http\Controllers;

use App\Http\Resources\CalculationSyncStatusResource;
use App\Http\Resources\DashboardStatsResource;
use App\Http\Resources\KnowledgeCoverageResource;
use App\Managers\CalculationRunManager;
use App\Managers\DashboardManager;
use App\Metrics\Snapshots\MetricScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardManager $dashboardManager,
        private readonly CalculationRunManager $calculationRunManager,
    ) {}

    /**
     * <summary>
     *  Sync status of the org-wide metric recalculation (aggregates refresh or the
     *  nightly full cascade) — state, last calculated time, live progress.
     * </summary>
     *
     * @return CalculationSyncStatusResource state, last_calculated_at, progress, error
     */
    public function getDashboardSyncStatus(): CalculationSyncStatusResource
    {
        // Act (Manager)
        $status = $this->calculationRunManager->getSyncStatus(MetricScope::Org, null);

        // Return (Controller)
        return new CalculationSyncStatusResource($status);
    }

    /**
     * <summary>
     *  Manually queue the FULL metric cascade (every project → every user → org
     *  aggregates), same as the nightly run. Debounced — no-op when an org-scope
     *  run is already pending. Returns the fresh sync status.
     * </summary>
     *
     * @return JsonResponse HTTP 202 with the sync-status payload
     */
    public function triggerFullRecalculation(): JsonResponse
    {
        // Act (Manager)
        $this->calculationRunManager->queueFullRecalculation();
        $status = $this->calculationRunManager->getSyncStatus(MetricScope::Org, null);

        // Return (Controller)
        return (new CalculationSyncStatusResource($status))->response()->setStatusCode(202);
    }

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

    /**
     * <summary>
     *  Upcoming Risk Events card — upcoming absences within the horizon, each with its computed
     *  per-project operational impact (bus factor / coverage before-after, lost skills, severity).
     * </summary>
     *
     * @param Request $request horizon_days query param (defaults to 30)
     * @return JsonResponse { generated_at, events[] }
     */
    public function getUpcomingRiskEvents(Request $request): JsonResponse
    {
        // Act (Manager)
        $payload = $this->dashboardManager->getUpcomingRiskEvents(
            (int) $request->integer('horizon_days', 30)
        );

        // Return (Controller)
        return response()->json($payload);
    }
}
