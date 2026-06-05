<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyPlanningRequest;
use App\Http\Requests\SimulatePlanningRequest;
use App\Services\PlanningService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanningController extends Controller
{
    public function __construct(private readonly PlanningService $service) {}

    /**
     * <summary>
     *  GET /api/planning?month=YYYY-MM — month roster with absences, capacity_today.
     * </summary>
     */
    public function index(Request $request): JsonResponse
    {
        $month = $request->query('month');
        if (!is_string($month) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = Carbon::now()->format('Y-m');
        }
        return response()->json($this->service->getMonth($month));
    }

    /**
     * <summary>
     *  POST /api/planning/simulate — what-if rich impact payload.
     * </summary>
     */
    public function simulate(SimulatePlanningRequest $request): JsonResponse
    {
        $data = $request->validated();
        return response()->json($this->service->simulate($data['absences'], $data['month'] ?? null));
    }

    /**
     * <summary>
     *  POST /api/planning/apply — persist simulated absences as planned leave.
     * </summary>
     */
    public function apply(ApplyPlanningRequest $request): JsonResponse
    {
        $data = $request->validated();
        return response()->json($this->service->apply($data['absences']));
    }
}
