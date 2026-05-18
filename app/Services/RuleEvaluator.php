<?php

namespace App\Services;

use App\Enums\RuleScope;
use App\Enums\RuleType;
use App\Models\Project;
use App\Models\Rule;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Support\Collection;

class RuleEvaluator
{
    public function __construct(
        private readonly RuleService           $ruleService,
        private readonly SkillCoverageService  $coverage,
        private readonly RiskCalculationService $risk,
    ) {}

    /**
     * <summary>
     *  Evaluate every enabled rule against the current live org state.
     *  Returns a flat list of violations. No persistence — caller caches if needed.
     * </summary>
     *
     * @return array<int, array{rule_id:int, rule_name:string, type:string, scope_type:string, scope_id:?int, subject_type:string, subject_id:?int, message:string}>
     */
    public function evaluateOrganization(): array
    {
        $violations = [];

        foreach ($this->ruleService->getEnabledRules() as $rule) {
            foreach ($this->evaluateRule($rule, []) as $v) {
                $violations[] = $v;
            }
        }

        return $violations;
    }

    /**
     * <summary>
     *  Evaluate every enabled rule against a simulated absence roster.
     *  $absentUserIds is the set of users virtually removed for the simulation.
     * </summary>
     *
     * @param Simulation $simulation Target simulation
     * @return array<int, array>
     */
    public function evaluateSimulation(Simulation $simulation): array
    {
        $absentUserIds = $simulation->absentUsers()->pluck('users.id')->all();
        $violations    = [];

        foreach ($this->ruleService->getEnabledRules() as $rule) {
            foreach ($this->evaluateRule($rule, $absentUserIds) as $v) {
                $violations[] = $v;
            }
        }

        return $violations;
    }

    /**
     * <summary>
     *  Dispatch a single rule to its type handler.
     * </summary>
     *
     * @param Rule $rule
     * @param array<int> $absentUserIds Users virtually removed (empty = live state)
     * @return array<int, array>
     */
    private function evaluateRule(Rule $rule, array $absentUserIds): array
    {
        return match ($rule->type) {
            RuleType::BusFactor->value      => $this->evaluateBusFactor($rule, $absentUserIds),
            RuleType::MinSkill->value       => $this->evaluateMinSkill($rule, $absentUserIds),
            RuleType::MinCoverage->value    => $this->evaluateMinCoverage($rule, $absentUserIds),
            RuleType::RoleRedundancy->value => $this->evaluateRoleRedundancy($rule, $absentUserIds),
            default                         => [],
        };
    }

    /**
     * <summary>
     *  Rule violated when at least one project in scope has bus_factor &gt; max_bus_factor threshold.
     *  Note: rule says "bus factor MUST NOT exceed N". Lower bus_factor = more risk; we flag when computed &lt; threshold.
     * </summary>
     */
    private function evaluateBusFactor(Rule $rule, array $absentUserIds): array
    {
        $max = (int) ($rule->params['max_bus_factor'] ?? 0);
        $projects   = $this->scopedProjects($rule);
        $violations = [];

        foreach ($projects as $project) {
            $bf = $this->risk->computeBusFactor($project);
            if ($bf > 0 && $bf <= $max) {
                $violations[] = $this->violation($rule, 'project', $project->id,
                    "Project '{$project->name}' bus factor is {$bf} (≤ threshold {$max}).");
            }
        }

        return $violations;
    }

    private function evaluateMinSkill(Rule $rule, array $absentUserIds): array
    {
        $skillId  = (int) ($rule->params['skill_id'] ?? 0);
        $minLevel = (int) ($rule->params['min_level'] ?? 1);
        $minCount = (int) ($rule->params['min_count'] ?? 1);

        $count = User::query()
            ->whereNotIn('id', $absentUserIds)
            ->whereHas('skills', fn($q) => $q->where('skills.id', $skillId)->where('user_skills.level', '>=', $minLevel))
            ->count();

        if ($count < $minCount) {
            return [$this->violation($rule, 'organization', null,
                "Only {$count} user(s) hold skill #{$skillId} at level ≥ {$minLevel} (need {$minCount}).")];
        }

        return [];
    }

    private function evaluateMinCoverage(Rule $rule, array $absentUserIds): array
    {
        $skillId    = (int) ($rule->params['skill_id'] ?? 0);
        $minPct     = (int) ($rule->params['min_pct'] ?? 0);
        $projects   = $this->scopedProjects($rule);
        $violations = [];

        foreach ($projects as $project) {
            $matrix = $absentUserIds
                ? $this->coverage->getCoverageAfterAbsence($project, $absentUserIds)
                : $this->coverage->getCoverage($project);

            $forSkill = collect($matrix)->firstWhere('skill_id', $skillId);
            if (!$forSkill) continue;

            $required = max(1, count($project->users));
            $covering = count($forSkill['employees']);
            $pct      = (int) round(($covering / $required) * 100);

            if ($pct < $minPct) {
                $violations[] = $this->violation($rule, 'project', $project->id,
                    "Project '{$project->name}' coverage for skill #{$skillId} is {$pct}% (need {$minPct}%).");
            }
        }

        return $violations;
    }

    private function evaluateRoleRedundancy(Rule $rule, array $absentUserIds): array
    {
        $role     = (string) ($rule->params['role'] ?? '');
        $minCount = (int) ($rule->params['min_count'] ?? 1);

        $count = User::query()
            ->whereNotIn('id', $absentUserIds)
            ->where('title', $role)
            ->count();

        if ($count < $minCount) {
            return [$this->violation($rule, 'organization', null,
                "Only {$count} user(s) hold role '{$role}' (need {$minCount}).")];
        }

        return [];
    }

    /**
     * @return Collection<int, Project>
     */
    private function scopedProjects(Rule $rule): Collection
    {
        return match ($rule->scope_type) {
            RuleScope::Project->value      => Project::where('id', $rule->scope_id)->get(),
            RuleScope::Department->value   => Project::whereHas('users',
                fn($q) => $q->where('department_id', $rule->scope_id))->get(),
            default                        => Project::all(),
        };
    }

    private function violation(Rule $rule, string $subjectType, ?int $subjectId, string $message): array
    {
        return [
            'rule_id'      => $rule->id,
            'rule_name'    => $rule->name,
            'type'         => $rule->type,
            'scope_type'   => $rule->scope_type,
            'scope_id'     => $rule->scope_id,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'message'      => $message,
        ];
    }
}
