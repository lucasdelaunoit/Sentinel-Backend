<?php

namespace App\Http\Controllers;

use App\Managers\SimulationManager;
use App\Models\Simulation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SimulationController extends Controller
{
    public function __construct(private readonly SimulationManager $manager) {}

    public function index(): JsonResponse
    {
        return response()->json($this->manager->list());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id'           => ['required', 'integer', 'exists:projects,id'],
            'name'                 => ['required', 'string', 'max:255'],
            'description'          => ['nullable', 'string'],
            'absent_employee_ids'  => ['required', 'array', 'min:1'],
            'absent_employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);

        return response()->json($this->manager->create($data), 201);
    }

    public function show(Simulation $simulation): JsonResponse
    {
        return response()->json($this->manager->get($simulation));
    }

    public function destroy(Simulation $simulation): JsonResponse
    {
        $this->manager->delete($simulation);

        return response()->json(null, 204);
    }
}
