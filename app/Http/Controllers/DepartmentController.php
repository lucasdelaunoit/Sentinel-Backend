<?php

namespace App\Http\Controllers;

use App\Managers\DepartmentManager;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentManager $manager) {}

    public function index(): JsonResponse
    {
        return response()->json($this->manager->list());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:departments,name'],
        ]);

        return response()->json($this->manager->create($data), 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', "unique:departments,name,{$department->id}"],
        ]);

        return response()->json($this->manager->update($department, $data));
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->manager->delete($department);

        return response()->json(null, 204);
    }
}
