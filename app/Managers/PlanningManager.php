<?php

namespace App\Managers;

use App\Services\PlanningService;
use App\Services\PlanningSimulationService;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Throwable;

class PlanningManager
{
    use Concerns\DispatchesProjectRecalculations;

    public function __construct(
        private readonly PlanningService $planningService,
        private readonly PlanningSimulationService $planningSimulationService,
        private readonly UserService $userService,
    ) {}

    /**
     * <summary>
     *  Retrieve the month roster payload (users, absences, capacity_today) for the Gantt view.
     * </summary>
     *
     * @param string $month Target month in YYYY-MM format
     * @return array Month payload
     */
    public function getPlanningMonth(string $month): array
    {
        return $this->planningService->getPlanningMonth($month);
    }

    /**
     * <summary>
     *  Run the what-if planning simulation for a virtual absence roster. Never writes source tables.
     * </summary>
     *
     * @param array<int, array<string, mixed>> $absences Virtual absence payloads
     * @param string|null $month Optional month scope in YYYY-MM format
     * @return array Rich impact payload
     */
    public function simulatePlanning(array $absences, ?string $month): array
    {
        return $this->planningSimulationService->simulate($absences, $month);
    }

    /**
     * <summary>
     *  Persist simulated absences as real planned leave inside a transaction, then dispatch
     *  risk recalculation for every project of the affected users.
     * </summary>
     *
     * @param array<int, array<string, mixed>> $absences Validated absence payloads
     * @return array{applied: int, created_ids: array<int, int>} Count and ids of created absences
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function applyPlanning(array $absences): array
    {
        $createdIds = DB::transaction(fn() => $this->planningService->applyPlanning($absences));

        $userIds = array_values(array_unique(array_map(fn(array $a) => (int) $a['user_id'], $absences)));
        $this->userService->getUsersWithProjectsByIds($userIds)->each(
            fn($user) => $this->dispatchProjectRecalculations($user)
        );

        return ['applied' => count($createdIds), 'created_ids' => $createdIds];
    }
}
