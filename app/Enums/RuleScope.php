<?php

namespace App\Enums;

enum RuleScope: string
{
    case Organization = 'organization';
    case Project = 'project';
    case Department = 'department';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn($c) => $c->value, self::cases());
    }
}
