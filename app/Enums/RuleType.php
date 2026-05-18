<?php

namespace App\Enums;

enum RuleType: string
{
    case BusFactor      = 'bus_factor';
    case MinSkill       = 'min_skill';
    case MinCoverage    = 'min_coverage';
    case RoleRedundancy = 'role_redundancy';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn($c) => $c->value, self::cases());
    }
}
