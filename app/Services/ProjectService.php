<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ProjectService
{
    public function getAgileProjects(Request $request): LengthAwarePaginator
    {
        if ($request->filled('search') && !$request->has('filter.search')) {
            $request->merge(['filter' => array_merge($request->input('filter', []), ['search' => $request->input('search')])]);
        }

        return QueryBuilder::for(Project::class, $request)
            ->withCount('employees')
            ->allowedFilters([
                AllowedFilter::callback('search', function ($query, $value) {
                    $query->where('name', 'like', "%{$value}%");
                }),
                AllowedFilter::exact('status'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('status'),
                AllowedSort::field('progress'),
                AllowedSort::field('risk_score'),
                AllowedSort::field('health'),
                AllowedSort::field('created_at'),
            ])
            ->defaultSort('-created_at')
            ->paginate($request->integer('per_page', 15))
            ->appends($request->query());
    }
}
