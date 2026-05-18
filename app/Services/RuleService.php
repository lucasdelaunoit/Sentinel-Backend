<?php

namespace App\Services;

use App\Models\Rule;
use App\Support\QueryParams;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RuleService
{
    /**
     * <summary>
     *  Paginated list of Rule rows, filterable by type/scope_type/enabled.
     * </summary>
     *
     * @param QueryParams $params HTTP-agnostic query params
     * @return LengthAwarePaginator
     */
    public function getAgileRules(QueryParams $params): LengthAwarePaginator
    {
        return QueryBuilder::for(Rule::class, $params->toRequest())
            ->allowedFilters([
                AllowedFilter::exact('type'),
                AllowedFilter::exact('scope_type'),
                AllowedFilter::exact('scope_id'),
                AllowedFilter::exact('enabled'),
            ])
            ->allowedSorts(['name', 'type', 'created_at'])
            ->defaultSort('-created_at')
            ->paginate($params->perPage())
            ->appends($params->rawQuery());
    }

    /**
     * <summary>
     *  Return all enabled Rule rows. Used by the evaluator.
     * </summary>
     *
     * @return Collection<int, Rule>
     */
    public function getEnabledRules(): Collection
    {
        return Rule::where('enabled', true)->get();
    }

    /**
     * <summary>
     *  Persist a new Rule row.
     * </summary>
     *
     * @param array $data Validated payload
     * @return Rule Created rule
     */
    public function createRule(array $data): Rule
    {
        return Rule::create($data);
    }

    /**
     * <summary>
     *  Update a single Rule row.
     * </summary>
     *
     * @param Rule $rule Target rule
     * @param array $data Validated payload
     * @return Rule Freshly reloaded rule
     */
    public function updateRule(Rule $rule, array $data): Rule
    {
        $rule->update($data);

        return $rule->fresh();
    }

    /**
     * <summary>
     *  Hard-delete a single Rule row.
     * </summary>
     *
     * @param Rule $rule Target rule
     * @return void
     */
    public function deleteRule(Rule $rule): void
    {
        $rule->delete();
    }
}
