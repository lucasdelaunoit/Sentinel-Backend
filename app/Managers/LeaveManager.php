<?php

namespace App\Managers;

use App\Models\Employee;
use App\Models\Leave;
use Illuminate\Database\Eloquent\Collection;

class LeaveManager
{
    public function getByEmployee(Employee $employee): Collection
    {
        return $employee->leaves()->orderByDesc('start_date')->get();
    }

    public function create(Employee $employee, array $data): Leave
    {
        return $employee->leaves()->create($data);
    }

    public function update(Leave $leave, array $data): Leave
    {
        $leave->update($data);

        return $leave->fresh();
    }

    public function delete(Leave $leave): void
    {
        $leave->delete();
    }
}
