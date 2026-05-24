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

    // Org-scoped (projects aggregates)
    case ProjectsTotal = 'projects_total';
    case ProjectsAvgFragility = 'projects_avg_fragility';
    case ProjectsFragileCount = 'projects_fragile_count';
    case ProjectsDeadlinePressure = 'projects_deadline_pressure';

    // Org-scoped (users aggregates)
    case UsersTotal = 'users_total';
    case UsersAvailable = 'users_available';
    case UsersCritical = 'users_critical';
    case UsersUniqueSkillHolders = 'users_unique_skill_holders';

    // Org-scoped (dashboard aggregates)
    case DashboardWorstFragility = 'dashboard_worst_fragility';
    case DashboardKnowledgeCoverage = 'dashboard_knowledge_coverage';
    case DashboardTeamAvailability = 'dashboard_team_availability';
    case DashboardAbsenceImpact = 'dashboard_absence_impact';
}
