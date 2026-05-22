<?php

namespace App\Metrics;

/**
 * Scope a metric snapshot belongs to.
 *  - Project / User / SkillCategory: per-entity row, scope_id = entity id.
 *  - Org: org-wide rollup, scope_id null.
 */
enum MetricScope: string
{
    case Project = 'project';
    case User = 'user';
    case Org = 'org';
    case SkillCategory = 'skill_category';
}
