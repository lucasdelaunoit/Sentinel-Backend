<?php

namespace App\Metrics\Snapshots;

/**
 * Identifies what metric a snapshot row holds.
 * Add a case here before capturing a new metric — keeps the metric_snapshots
 * `metric` column constrained to known values.
 */
enum MetricKey: string
{
    // Project-scoped
    case Fragility = 'fragility';
    case TeamAvailability = 'team_availability';
    case KnowledgeCoverage = 'knowledge_coverage';

    // User-scoped
    case Criticality = 'criticality';
    case BusFactorInOrg = 'bus_factor_in_org';
    case SkillsCount = 'skills_count';
    case ActiveProjects = 'active_projects';
}
