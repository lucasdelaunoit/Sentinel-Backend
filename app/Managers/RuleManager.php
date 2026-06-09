<?php

namespace App\Managers;

use App\Jobs\RecalculateProjectRiskJob;
use App\Jobs\RefreshRuleViolationsJob;
use App\Models\Project;
use App\Models\Rule;
use App\Services\RuleEvaluator;
use App\Services\RuleService;
use App\Support\QueryParams;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class RuleManager
{
    public const VIOLATIONS_CACHE_KEY = 'rules:violations:org';
    public const VIOLATIONS_CACHE_TTL = 3600;

    public function __construct(
        private readonly RuleService   $ruleService,
        private readonly RuleEvaluator $ruleEvaluator,
    ) {}

    /**
     * <summary>
     *  Paginated list of Rule rows.
     * </summary>
     *
     * @param QueryParams $params
     * @return LengthAwarePaginator
     */
    public function getAgileRules(QueryParams $params): LengthAwarePaginator
    {
        return $this->ruleService->getAgileRules($params);
    }

    /**
     * <summary>
     *  Create a Rule inside a transaction. Dispatches RefreshRuleViolationsJob.
     * </summary>
     *
     * @param array $data Validated payload
     * @return Rule
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function createRule(array $data): Rule
    {
        $rule = DB::transaction(fn() => $this->ruleService->createRule($data));
        RefreshRuleViolationsJob::dispatch();
        $this->dispatchRecalcForRuleScope($rule);
        return $rule;
    }

    /**
     * <summary>
     *  Update a Rule inside a transaction. Dispatches RefreshRuleViolationsJob.
     * </summary>
     *
     * @param Rule $rule Target rule
     * @param array $data Validated payload
     * @return Rule
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function updateRule(Rule $rule, array $data): Rule
    {
        $rule = DB::transaction(fn() => $this->ruleService->updateRule($rule, $data));
        RefreshRuleViolationsJob::dispatch();
        $this->dispatchRecalcForRuleScope($rule);
        return $rule;
    }

    /**
     * <summary>
     *  Hard-delete a Rule inside a transaction. Dispatches RefreshRuleViolationsJob.
     * </summary>
     *
     * @param Rule $rule Target rule
     * @return void
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function deleteRule(Rule $rule): void
    {
        $snapshot = clone $rule;
        DB::transaction(fn() => $this->ruleService->deleteRule($rule));
        RefreshRuleViolationsJob::dispatch();
        $this->dispatchRecalcForRuleScope($snapshot);
    }

    /**
     * <summary>
     *  Dispatch RecalculateProjectRiskJob for projects affected by the rule's scope.
     *  Scope project -&gt; that single project. Scope department -&gt; projects with any user in dept.
     *  Scope organization (or null) -&gt; all non-archived projects.
     * </summary>
     */
    private function dispatchRecalcForRuleScope(Rule $rule): void
    {
        $query = Project::query()->whereNull('archived_at');

        match ($rule->scope_type) {
            'project'    => $query->where('id', $rule->scope_id),
            'department' => $query->whereHas('users', fn($q) => $q->where('department_id', $rule->scope_id)),
            default      => null,
        };

        $query->get(['id'])->each(fn(Project $p) => RecalculateProjectRiskJob::dispatch($p));
    }

    /**
     * <summary>
     *  Return the org-wide current violation snapshot (read-through cache).
     *  Cache TTL is one hour; refreshed eagerly by the daily scheduled job and on every rule mutation.
     * </summary>
     *
     * @return array Flat list of violations
     */
    public function getOrganizationViolations(): array
    {
        return Cache::remember(
            self::VIOLATIONS_CACHE_KEY,
            self::VIOLATIONS_CACHE_TTL,
            fn() => $this->ruleEvaluator->evaluateOrganization(),
        );
    }

    /**
     * <summary>
     *  Force-recompute and overwrite the org-wide violation cache. Used by RefreshRuleViolationsJob.
     * </summary>
     *
     * @return array Refreshed list of violations
     */
    public function refreshOrganizationViolations(): array
    {
        $violations = $this->ruleEvaluator->evaluateOrganization();
        Cache::put(self::VIOLATIONS_CACHE_KEY, $violations, self::VIOLATIONS_CACHE_TTL);
        return $violations;
    }

}
