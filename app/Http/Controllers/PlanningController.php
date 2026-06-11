<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyPlanningRequest;
use App\Http\Requests\SimulatePlanningRequest;
use App\Managers\PlanningManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanningController extends Controller
{
    public function __construct(
        private readonly PlanningManager $planningManager,
    ) {}

    /**
     * <summary>
     *  GET /api/planning?month=YYYY-MM — month roster with absences, capacity_today.
     *  Falls back to the current month when the param is missing or malformed.
     * </summary>
     *
     * @param Request $request Query carrying the optional month param
     * @return JsonResponse Month payload
     */
    public function getPlanningMonth(Request $request): JsonResponse
    {
        // Validate & authorize (Controller)
        $month = $request->query('month');
        if (!is_string($month) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = Carbon::now()->format('Y-m');
        }

        // Act (Manager)
        $payload = $this->planningManager->getPlanningMonth($month);

        // Return (Controller)
        return response()->json($payload);
    }

    /**
     * <summary>
     *  POST /api/planning/simulate — what-if rich impact payload. Never writes source tables.
     * </summary>
     *
     * @param SimulatePlanningRequest $request Validated virtual absences and optional month
     * @return JsonResponse Simulation impact payload
     */
    public function simulatePlanning(SimulatePlanningRequest $request): JsonResponse
    {
        // Act (Manager)
        $data = $request->validated();
        $result = $this->planningManager->simulatePlanning($data['absences'], $data['month'] ?? null);

        // Return (Controller)
        return response()->json($result);
    }

    /**
     * <summary>
     *  POST /api/planning/apply — persist simulated absences as planned leave.
     * </summary>
     *
     * @param ApplyPlanningRequest $request Validated absence payloads
     * @return JsonResponse Applied count and created ids
     */
    public function applyPlanning(ApplyPlanningRequest $request): JsonResponse
    {
        // Act (Manager)
        $result = $this->planningManager->applyPlanning($request->validated()['absences']);

        // Return (Controller)
        return response()->json($result);
    }
}
