<?php

namespace App\Managers;

use App\Models\Skill;
use App\Models\SkillCategory;
use App\Models\User;
use App\Services\RiskCalculationService;
use App\Services\SkillService;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
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
     * @param Request $request Pagination, filter, sort & search parameters
     * @return LengthAwarePaginator Paginated list of skill
     */
    public function getAgileSkills(Request $request): LengthAwarePaginator
    {
        return $this->skillService->getAgileSkills($request);
    }

    // Skills

    public function listSkills(array $filters = []): Collection
    {
        return $this->skillService->listSkills($filters);
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

    public function getAgileSkillsForUser(Request $request, User $user): LengthAwarePaginator
    {
        return $this->skillService->getAgileSkillsForUser($request, $user);
    }

    public function getUserSkills(User $user): SupportCollection
    {
        return $this->skillService->getUserSkills($user);
    }
}
