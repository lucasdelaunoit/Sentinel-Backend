<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Managers\DepartmentManager;
use App\Models\Department;
use App\Support\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepartmentController extends Controller
{
    public function __construct(
        private readonly DepartmentManager $departmentManager,
    ) {}

    /**
     * <summary>
     *  Retrieve all departments (paginated, filterable, sortable, searchable).
     * </summary>
     *
     * @param Request $request Pagination, filter (search), sort parameters
     * @return AnonymousResourceCollection Paginated list of departments
     */
    public function getAgileDepartments(Request $request): AnonymousResourceCollection
    {
        // Act (Manager)
        $departments = $this->departmentManager->getAgileDepartments(QueryParams::fromRequest($request));

        // Return (Controller)
        return DepartmentResource::collection($departments);
    }

    /**
     * <summary>
     *  Create a new department.
     * </summary>
     *
     * @param StoreDepartmentRequest $request name
     * @return JsonResponse Created department — HTTP 201
     */
    public function createDepartment(StoreDepartmentRequest $request): JsonResponse
    {
        // Act (Manager)
        $department = $this->departmentManager->createDepartment($request->validated());

        // Return (Controller)
        return DepartmentResource::make($department)->response()->setStatusCode(201);
    }

    /**
     * <summary>
     *  Update an existing department.
     * </summary>
     *
     * @param UpdateDepartmentRequest $request Fields to update (all optional)
     * @param Department $department Route-model bound department
     * @return DepartmentResource Updated department with users_count
     */
    public function updateDepartment(UpdateDepartmentRequest $request, Department $department): DepartmentResource
    {
        // Act (Manager)
        $department = $this->departmentManager->updateDepartment($department, $request->validated());

        // Return (Controller)
        return DepartmentResource::make($department);
    }

    /**
     * <summary>
     *  Delete a department.
     * </summary>
     *
     * @param Department $department Route-model bound department
     * @return JsonResponse HTTP 204 No Content
     */
    public function deleteDepartment(Department $department): JsonResponse
    {
        // Act (Manager)
        $this->departmentManager->deleteDepartment($department);

        // Return (Controller)
        return response()->json(null, 204);
    }
}
