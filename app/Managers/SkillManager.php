<?php

namespace App\Managers;

use App\Models\Skill;
use App\Models\SkillCategory;
use App\Models\User;
use App\Services\RiskCalculationService;
use App\Services\SkillService;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class SkillManager
{
    public function __construct(
        private readonly RiskCalculationService $riskService,
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




    public function updateCategory(SkillCategory $category, array $data): SkillCategory
    {
        return $this->skillService->updateCategory($category, $data);
    }

    public function deleteCategory(SkillCategory $category): void
    {
        abort_if($category->skills()->exists(), 409, 'Cannot delete a category that has skills assigned to it.');

        $this->skillService->deleteCategory($category);
    }

    public function getKCI(SkillCategory $category): array
    {
        return [
            'category_id'   => $category->id,
            'category_name' => $category->name,
            'kci'           => $this->riskService->computeKCI($category),
        ];
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
