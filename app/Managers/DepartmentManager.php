<?php

namespace App\Managers;

use App\Models\Department;
use App\Services\DepartmentService;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DepartmentManager
{
    public function __construct(
        private readonly DepartmentService $departmentService,
    ) {}

    /**
     * <summary>
     *  Retrieve all departments (paginated, filterable, sortable, searchable).
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter, sort & search parameters
     * @return LengthAwarePaginator Paginated list of departments
     */
    public function getAgileDepartments(QueryParams $params): LengthAwarePaginator
    {
        return $this->departmentService->getAgileDepartments($params);
    }

    /**
     * <summary>
     *  Create a new Department.
     * </summary>
     *
     * @param array<string, mixed> $data Validated payload (name)
     * @return Department Newly created department
     */
    public function createDepartment(array $data): Department
    {
        return $this->departmentService->createDepartment($data);
    }

    /**
     * <summary>
     *  Update a Department.
     * </summary>
     *
     * @param Department $department Target department
     * @param array<string, mixed> $data Validated fields (name?)
     * @return Department Refreshed department with users_count
     */
    public function updateDepartment(Department $department, array $data): Department
    {
        return $this->departmentService->updateDepartment($department, $data);
    }

    /**
     * <summary>
     *  Delete a Department.
     * </summary>
     *
     * @param Department $department Target department
     * @return void
     */
    public function deleteDepartment(Department $department): void
    {
        $this->departmentService->deleteDepartment($department);
    }
}
