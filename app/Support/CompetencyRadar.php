<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * <summary>
 *  Pure competency-radar math shared by UserService and ProjectService.
 *  One axis per SkillCategory: value = round(avg(level) / 5 * 100) over the supplied
 *  skill rows belonging to the category. Categories with no held skill return 0.
 * </summary>
 */
class CompetencyRadar
{
    /**
     * <summary>
     *  Build the radar rows from a category list and a flat collection of held skills.
     *  Pure computation — no DB access. Each skill must expose skill_category_id and pivot->level.
     * </summary>
     *
     * @param Collection $categories SkillCategory rows (id, name), already ordered
     * @param iterable $skills Flat list of Skill models with user_skills pivot loaded
     * @param int $target Radar target value (fixed at 80 until a setting is wired)
     * @return array<int, array{category: string, value: int, target: int}>
     */
    public static function build(Collection $categories, iterable $skills, int $target = 80): array
    {
        $sums = [];
        $counts = [];
        foreach ($categories as $category) {
            $sums[$category->id] = 0;
            $counts[$category->id] = 0;
        }

        foreach ($skills as $skill) {
            $categoryId = $skill->skill_category_id;
            if (!isset($sums[$categoryId])) {
                continue;
            }
            $sums[$categoryId] += (int) $skill->pivot->level;
            $counts[$categoryId]++;
        }

        $result = [];
        foreach ($categories as $category) {
            $count = $counts[$category->id];
            $value = $count === 0 ? 0 : (int) round(($sums[$category->id] / $count) / 5 * 100);

            $result[] = [
                'category' => $category->name,
                'value' => $value,
                'target' => $target,
            ];
        }

        return $result;
    }
}
