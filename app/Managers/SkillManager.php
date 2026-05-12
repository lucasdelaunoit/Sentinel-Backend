<?php

namespace App\Managers;

use App\Models\Skill;
use App\Models\User;
use App\Services\SkillService;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SkillManager
{
    public function __construct(
        private readonly SkillService $skillService,
    ) {}

    /**
     * <summary>
     *  Retrieve all skills (paginated, filterable, sortable).
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter & sort parameters
     * @return LengthAwarePaginator Paginated list of skills
     */
    public function getAgileSkills(QueryParams $params): LengthAwarePaginator
    {
        return $this->skillService->getAgileSkills($params);
    }

    public function createSkill(array $data): Skill
    {
        return $this->skillService->createSkill($data);
    }

    public function updateSkill(Skill $skill, array $data): Skill
    {
        return $this->skillService->updateSkill($skill, $data);
    }

    /**
     * <summary>
     *  Soft-delete a Skill and cascade-detach it from all users and project requirements
     *  inside a single transaction. Orchestrates SkillService — single-responsibility methods.
     *  Affected project IDs are collected before the transaction for later recalculation.
     * </summary>
     *
     * @param Skill $skill Target skill to soft-delete
     * @return void
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function deleteSkill(Skill $skill): void
    {
        $affectedProjectIds = $this->skillService->getProjectsForSkill($skill);

        DB::transaction(function () use ($skill) {
            $this->skillService->detachSkillFromAllUsers($skill);
            $this->skillService->detachSkillFromAllProjects($skill);
            $this->skillService->deleteSkill($skill);
        });

        // TODO: dispatch RecalculateProjectRiskJob for $affectedProjectIds
    }

    // User skills

    public function getAgileSkillsForUser(QueryParams $params, User $user): LengthAwarePaginator
    {
        return $this->skillService->getAgileSkillsForUser($params, $user);
    }
}
