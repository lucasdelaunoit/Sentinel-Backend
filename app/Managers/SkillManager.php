<?php

namespace App\Managers;

use App\Models\Skill;
use App\Models\User;
use App\Services\SkillService;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

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

    public function deleteSkill(Skill $skill): void
    {
        $this->skillService->deleteSkill($skill);
    }




    // User skills

    public function getAgileSkillsForUser(QueryParams $params, User $user): LengthAwarePaginator
    {
        return $this->skillService->getAgileSkillsForUser($params, $user);
    }

    public function getUserSkills(User $user): SupportCollection
    {
        return $this->skillService->getUserSkills($user);
    }
}
