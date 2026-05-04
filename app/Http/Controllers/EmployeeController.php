<?php

namespace App\Http\Controllers;

use App\Managers\EmployeeManager;
use App\Models\Employee;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeManager $employeeManager
    ) {}

    // TODO : Add response resources for better response formatting and documentation
    public function index(Request $request): JsonResponse
    {
        $employees = $this->employeeManager->getAgileEmployees($request);

        return response()->json($employees);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'unique:employees,email'],
            'title'         => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        return response()->json($this->employeeManager->create($data), 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json($this->employeeManager->get($employee));
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:255'],
            'email'         => ['sometimes', 'email', "unique:employees,email,{$employee->id}"],
            'title'         => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        return response()->json($this->employeeManager->update($employee, $data));
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->employeeManager->delete($employee);

        return response()->json(null, 204);
    }

    public function skills(Employee $employee): JsonResponse
    {
        return response()->json($this->employeeManager->getSkills($employee));
    }

    public function attachSkill(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'skill_id' => ['required', 'integer', 'exists:skills,id'],
            'level'    => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $this->employeeManager->attachSkill($employee, $data['skill_id'], $data['level']);

        return response()->json(['message' => 'Skill added']);
    }

    public function updateSkill(Request $request, Employee $employee, Skill $skill): JsonResponse
    {
        $data = $request->validate([
            'level' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $this->employeeManager->updateSkill($employee, $skill->id, $data['level']);

        return response()->json(['message' => 'Skill level updated']);
    }

    public function detachSkill(Employee $employee, Skill $skill): JsonResponse
    {
        $this->employeeManager->detachSkill($employee, $skill->id);

        return response()->json(null, 204);
    }

    public function criticality(Employee $employee): JsonResponse
    {
        return response()->json($this->employeeManager->getCriticality($employee));
    }

    public function today(): JsonResponse
    {
        return response()->json($this->employeeManager->getTodayStatuses());
    }
}
