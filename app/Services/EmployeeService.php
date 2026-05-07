<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class EmployeeService
{
    public function getAgileEmployees(Request $request): LengthAwarePaginator
    {
        if ($request->filled('search') && !$request->has('filter.search')) {
            $request->merge(['filter' => array_merge($request->input('filter', []), ['search' => $request->input('search')])]);
        }

        return QueryBuilder::for(Employee::class, $request)
            ->with(['department', 'skills.category'])
            ->allowedFilters([
                AllowedFilter::callback('search', function ($query, $value) {
                    $query->where(fn($q) => $q
                        ->where('name', 'like', "%{$value}%")
                        ->orWhere('email', 'like', "%{$value}%")
                    );
                }),
                AllowedFilter::exact('department_id'),
                AllowedFilter::callback('skill_id', function ($query, $value) {
                    $query->whereHas('skills', fn($q) => $q->where('skills.id', $value));
                }),
                AllowedFilter::callback('status', function ($query, $value) {
                    $status = EmployeeStatus::tryFrom($value);
                    if ($status === null) return;

                    $today = now()->toDateString();
                    $hasLeave = fn($q) => $q
                        ->where('start_date', '<=', $today)
                        ->where('end_date', '>=', $today);

                    if ($status === EmployeeStatus::Away) {
                        $query->whereHas('leaves', $hasLeave);
                    } else {
                        $query->whereDoesntHave('leaves', $hasLeave);
                    }
                }),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('title'),
                AllowedSort::field('created_at'),
            ])
            ->defaultSort('name')
            ->paginate($request->integer('per_page', 20))
            ->appends($request->query());
    }
}
