<?php

namespace App\Managers;

use App\Models\Skill;
use App\Models\User;
use App\Services\SkillService;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    /**
     * <summary>
     *  Create a new Skill.
     * </summary>
     *
     * @param array<string, mixed> $data Validated payload (name, skill_category_id)
     * @return Skill Newly created skill
     */
    public function createSkill(array $data): Skill
    {
        return $this->skillService->createSkill($data);
    }

    /**
     * <summary>
     *  Update a Skill (name and/or skill_category_id) and return the fresh model with its category.
     * </summary>
     *
     * @param Skill $skill Target skill
     * @param array<string, mixed> $data Validated payload (name?, skill_category_id?)
     * @return Skill Updated skill with category eager-loaded
     */
    public function updateSkill(Skill $skill, array $data): Skill
    {
        // TODO: when skill_category_id changes, dispatch KCI recalculation for old + new categories
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
        // TODO: dispatch RecalculateProjectMetricsJob for $this->skillService->getProjectsForSkill($skill)
        //       before the transaction so affected projects are known
        DB::transaction(function () use ($skill) {
            $this->skillService->detachSkillFromAllUsers($skill);
            $this->skillService->detachSkillFromAllProjects($skill);
            $this->skillService->deleteSkill($skill);
        });
    }

    /**
     * <summary>
     *  Retrieve the skills attached to a user (paginated, filterable, sortable).
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter & sort parameters
     * @param User $user Target user whose skills are listed
     * @return LengthAwarePaginator Paginated list of the user's skills
     */
    public function getAgileSkillsForUser(QueryParams $params, User $user): LengthAwarePaginator
    {
        return $this->skillService->getAgileSkillsForUser($params, $user);
    }
}
