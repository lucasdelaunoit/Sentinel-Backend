<?php

namespace App\Managers;

use App\Models\Simulation;
use App\Services\SimulationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SimulationManager
{
    public function __construct(
        private readonly SimulationService $simulation,
    ) {}

    public function list(): LengthAwarePaginator
    {
        return Simulation::query()
            ->with('project')
            ->withCount('absentEmployees')
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    public function create(array $data): Simulation
    {
        $simulation = DB::transaction(function () use ($data) {
            $sim = Simulation::create([
                'project_id'  => $data['project_id'],
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            if (!empty($data['absent_employee_ids'])) {
                $sim->absentEmployees()->sync($data['absent_employee_ids']);
            }

            return $sim;
        });

        $result = $this->simulation->run($simulation);

        $simulation->update(['result' => $result]);
        $simulation->result = $result;

        return $simulation->load(['project', 'absentEmployees']);
    }

    public function get(Simulation $simulation): Simulation
    {
        return $simulation->loadMissing(['project', 'absentEmployees']);
    }

    public function delete(Simulation $simulation): void
    {
        $simulation->delete();
    }
}
