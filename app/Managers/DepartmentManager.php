<?php

namespace App\Managers;

use App\Models\Department;
use Illuminate\Database\Eloquent\Collection;

class DepartmentManager
{
    public function list(): Collection
    {
        return Department::withCount('employees')->orderBy('name')->get();
    }

    public function create(array $data): Department
    {
        return Department::create($data);
    }

    public function update(Department $department, array $data): Department
    {
        $department->update($data);

        return $department->fresh();
    }

    public function delete(Department $department): void
    {
        $department->delete();
    }
}
