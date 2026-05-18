<?php

namespace App\Services;

use App\Models\Project;
use App\Models\SkillCategory;
use App\Models\User;

/**
 * STUB — calculation layer wiped. Returns hardcoded realistic values so
 * controllers/managers compile while the real engine is rebuilt from
 * settings + projects/users/skills + rules.
 *
 * Tier mapping helpers stay — they are pure label functions, not calculations.
 *
 * TODO: replace every compute* method with the real implementation.
 */
class RiskCalculationService
{
    public function __construct(
        private readonly SkillCoverageService       $coverage,
        private readonly OrganizationSettingService $orgSettings,
    ) {}

    public function computeBusFactor(Project $project): int
    {
        // TODO: real implementation — greedy set-cover over coverage matrix.
        return 3;
    }

    public function computeFragilityRaw(Project $project): float
    {
        // TODO: real implementation — weighted blend from settings.
        return 42.5;
    }

    public function computeTrajectoryRaw(Project $project): float
    {
        // TODO: real implementation — (100 - fragility) blended with progress.
        return 67.0;
    }

    public function computeKCI(SkillCategory $category): float
    {
        // TODO: real implementation — % users at level >= kci_min_level in category.
        return 75.0;
    }

    public function computeUserCriticality(User $user): array
    {
        // TODO: real implementation — unique skills + silo participation + bus factor contribution.
        return [
            'score'               => 35,
            'unique_skills'       => 1,
            'silo_count'          => 1,
            'bus_factor_projects' => 0,
        ];
    }

    public static function fragilityTier(float|int $raw): string
    {
        return match (true) {
            $raw <= 20 => 'solid',
            $raw <= 40 => 'stable',
            $raw <= 60 => 'stretched',
            $raw <= 80 => 'fragile',
            default    => 'critical',
        };
    }

    public static function trajectoryTier(float|int $raw): string
    {
        return match (true) {
            $raw <= 20 => 'off_course',
            $raw <= 40 => 'drifting',
            $raw <= 60 => 'wobbling',
            $raw <= 80 => 'on_track',
            default    => 'cruising',
        };
    }
}
