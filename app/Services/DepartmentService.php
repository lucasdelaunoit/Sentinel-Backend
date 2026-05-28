<?php

namespace App\Services;

use App\Models\Department;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class DepartmentService
{
    /**
     * <summary>
     *  Retrieve all departments (paginated, filterable, sortable, searchable) with users count.
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter, sort & search parameters
     * @return LengthAwarePaginator Paginated list of departments with users_count
     */
    public function getAgileDepartments(QueryParams $params): LengthAwarePaginator
    {
        return QueryBuilder::for(Department::class, $params->toRequest())
            ->withCount('users')
            ->allowedFilters([
                AllowedFilter::callback('search', fn($q, $v) => $q->where('name', 'like', "%{$v}%")),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('users_count'),
                AllowedSort::field('created_at'),
            ])
            ->defaultSort('name')
            ->paginate($params->perPage())
            ->appends($params->rawQuery());
    }

    /**
     * <summary>
     *  Create a new Department row.
     * </summary>
     *
     * @param array<string, mixed> $data Validated payload (name)
     * @return Department Newly created department
     */
    public function createDepartment(array $data): Department
    {
        return Department::create($data);
    }

    /**
     * <summary>
     *  Update a Department row and return the refreshed model with users count.
     * </summary>
     *
     * @param Department $department Target department
     * @param array<string, mixed> $data Validated fields to update (name?)
     * @return Department Refreshed department with users_count
     */
    public function updateDepartment(Department $department, array $data): Department
    {
        $department->update($data);

        return $department->fresh()->loadCount('users');
    }

    /**
     * <summary>
     *  Delete a single Department row. Does not touch related users.
     * </summary>
     *
     * @param Department $department Target department
     * @return void
     */
    public function deleteDepartment(Department $department): void
    {
        $department->delete();
    }
}
