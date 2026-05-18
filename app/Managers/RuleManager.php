<?php

namespace App\Managers;

use App\Jobs\RefreshRuleViolationsJob;
use App\Models\Rule;
use App\Models\Simulation;
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
        DB::transaction(fn() => $this->ruleService->deleteRule($rule));
        RefreshRuleViolationsJob::dispatch();
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

    /**
     * <summary>
     *  Evaluate every enabled rule against a simulation roster. Pure compute — no caching.
     * </summary>
     *
     * @param Simulation $simulation Target simulation
     * @return array Violations
     */
    public function evaluateSimulation(Simulation $simulation): array
    {
        return $this->ruleEvaluator->evaluateSimulation($simulation);
    }
}
