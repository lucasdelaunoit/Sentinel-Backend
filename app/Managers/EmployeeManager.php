<?php

namespace App\Managers;

use App\Enums\EmployeeStatus;
use App\Jobs\RecalculateProjectRiskJob;
use App\Models\Employee;
use App\Services\EmployeeService;
use App\Services\RiskCalculationService;
use Database\Seeders\EmployeeSkillSeeder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class EmployeeManager
{
    public function __construct(
        private readonly RiskCalculationService $risk,
        private readonly EmployeeService $employeeService,
    ) {}

    public function getAgileEmployees(Request $request): LengthAwarePaginator
    {
        return $this->employeeService->getAgileEmployees($request);
    }

    public function create(array $data): Employee
    {
        return DB::transaction(fn() => Employee::create($data));
    }

    public function get(Employee $employee): Employee
    {
        return $employee->loadMissing([
            'department',
            'skills.category',
            'projects',
            'leaves',
        ]);
    }

    public function update(Employee $employee, array $data): Employee
    {
        $employee->update($data);

        return $employee->fresh(['department']);
    }

    public function delete(Employee $employee): void
    {
        $employee->delete();
    }

    public function getSkills(Employee $employee): \Illuminate\Support\Collection
    {
        $employee->loadMissing('skills.category');

        return $employee->skills->map(fn($skill) => [
            'id'       => $skill->id,
            'name'     => $skill->name,
            'category' => $skill->category?->name,
            'level'    => $skill->pivot->level,
        ]);
    }

    public function attachSkill(Employee $employee, int $skillId, int $level): void
    {
        DB::transaction(function () use ($employee, $skillId, $level) {
            $employee->skills()->syncWithoutDetaching([$skillId => ['level' => $level]]);
        });

        $this->dispatchProjectRecalculations($employee);
    }

    public function updateSkill(Employee $employee, int $skillId, int $level): void
    {
        DB::transaction(function () use ($employee, $skillId, $level) {
            $employee->skills()->updateExistingPivot($skillId, ['level' => $level]);
        });

        $this->dispatchProjectRecalculations($employee);
    }

    public function detachSkill(Employee $employee, int $skillId): void
    {
        DB::transaction(function () use ($employee, $skillId) {
            $employee->skills()->detach($skillId);
        });

        $this->dispatchProjectRecalculations($employee);
    }

    public function getTodayStatuses(): array
    {
        $today = now()->toDateString();

        $employees = Employee::query()
            ->with(['leaves' => fn($q) => $q
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
            ])
            ->orderBy('name')
            ->get()
            ->map(fn($employee) => [
                'id'           => $employee->id,
                'name'         => $employee->name,
                'role'         => $employee->title,
                'initials'     => $this->deriveInitials($employee->name),
                'today_status' => $this->resolveStatus($employee)->value,
            ]);

        $total = $employees->count();
        $availableCount = $employees->where('today_status', EmployeeStatus::Available->value)->count();
        $capacityPct = $total > 0 ? (int) round(($availableCount / $total) * 100) : 100;

        $statusOrder = [EmployeeStatus::Away->value => 0, EmployeeStatus::Available->value => 1];
        $preview = $employees
            ->sortBy(fn($e) => $statusOrder[$e['today_status']] ?? 99)
            ->values()
            ->take(5);

        return [
            'capacity_pct' => $capacityPct,
            'total'        => $total,
            'employees'    => $preview->values()->all(),
        ];
    }

    public function getCriticality(Employee $employee): array
    {
        return $this->risk->computeEmployeeCriticality($employee);
    }

    private function resolveStatus(Employee $employee): EmployeeStatus
    {
        return $employee->leaves->isNotEmpty() ? EmployeeStatus::Away : EmployeeStatus::Available;
    }

    private function deriveInitials(string $name): string
    {
        $parts = array_filter(explode(' ', trim($name)));
        $initials = array_map(fn($p) => strtoupper(mb_substr($p, 0, 1)), array_values($parts));

        return implode('', array_slice($initials, 0, 2));
    }

    private function dispatchProjectRecalculations(Employee $employee): void
    {
        $employee->loadMissing('projects');

        foreach ($employee->projects as $project) {
            RecalculateProjectRiskJob::dispatch($project);
        }
    }
}
