<div align="center">

<img src="public/logo.svg" alt="Sentinel logo" width="96" />

# Sentinel

**Skill-based organizational risk analyzer â€” the API**

*Who can your team afford to lose? Find out before it hurts.*

![Laravel](https://img.shields.io/badge/Laravel_12-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP_8.2%2B-777BB4?logo=php&logoColor=white)
![Sanctum](https://img.shields.io/badge/Sanctum-FF2D20?logo=laravel&logoColor=white)
![Redis](https://img.shields.io/badge/Redis-DC382D?logo=redis&logoColor=white)
![Status](https://img.shields.io/badge/status-active_development-blueviolet)

</div>

---

## Table of contents

- [Why Sentinel](#why-sentinel)
- [The mental model](#the-mental-model)
- [The metrics pipeline](#the-metrics-pipeline)
  - [Layer 0 â€” Coverage matrix](#layer-0--coverage-matrix)
  - [Layer 1 â€” Atomic project metrics](#layer-1--atomic-project-metrics)
  - [Layer 2 â€” Composite fragility score](#layer-2--composite-fragility-score)
  - [Layer 3 â€” Organization aggregates](#layer-3--organization-aggregates)
  - [Layer 4 â€” Simulation](#layer-4--simulation)
- [Project status](#project-status)
- [Tech stack](#tech-stack)
- [Architecture](#architecture)
  - [The QueryParams boundary](#the-queryparams-boundary)
  - [Error handling](#error-handling)
  - [Directory map](#directory-map)
- [Domain model](#domain-model)
- [Recalculation system](#recalculation-system)
- [API overview](#api-overview)
- [Getting started](#getting-started)
- [Critical rules](#critical-rules)

---

## Why Sentinel

Every team has them: the one person who knows how the deployment pipeline works, the only engineer who can touch the billing code, the architect whose two-week vacation quietly freezes three projects. Organizations rarely see these single points of failure until they fail.

Sentinel makes them visible. It answers the questions every manager quietly worries about:

- *If Sarah leaves tomorrow, which projects catch fire?*
- *How many people actually understand this system?* (Spoiler: it's usually one.)
- *Can we survive August?*

It does this by modeling the organization as a graph of **skills**, **people**, and **projects** â€” and deriving every risk metric from that graph. No gut feeling, no survey, no spreadsheet archaeology. Just the data you already have about who knows what.

---

## The mental model

Sentinel is not a CRUD app wearing a dashboard. It is a deterministic pipeline:

```
INPUT DATA â†’ COVERAGE MATRIX â†’ DERIVED STATES â†’ METRICS â†’ DECISIONS
```

The heart of the system is the **Skill Coverage Matrix**. For every project, for every required skill: who can cover it, at what level â€” and are they even around within the planning horizon?

```
{
  project_id: {
    skill_id: {
      skill_id, skill_name, required_level,
      employees: [{ user_id, name, level }],
      status: "safe" | "siloed" | "uncovered"
    }
  }
}
```

Everything downstream is a function of this matrix. If coverage is correct, everything is correct. No metric bypasses it â€” that is the system's one non-negotiable invariant.

---

## The metrics pipeline

Five layers, each consuming only the layer below. Three inputs feed the whole thing: organization settings (weights, thresholds, horizons), project assignments and skill requirements, and user skills and absences. A fourth â€” hard-constraint rules feeding a fragility penalty â€” is designed but not yet built.

### Layer 0 â€” Coverage matrix

For each required skill on a project:

1. Filter assigned users to those **not absent** within `absence_horizon_days`.
2. Keep users whose skill level meets `required_level`.
3. Resolve status: `0` covering â†’ `uncovered` Â· `â‰¤ silo_threshold` â†’ `siloed` Â· otherwise â†’ `safe`.

### Layer 1 â€” Atomic project metrics

| Metric | Definition |
|---|---|
| `bus_factor` | Greedy set-cover minimum: smallest user set whose removal leaves at least one skill uncovered |
| `uncovered_ratio` | `count(uncovered) / count(required)` |
| `silo_ratio` | `count(siloed) / count(required)` |
| `absence_impact` | Skills that become uncovered once horizon absences apply, over total required |
| `rule_penalty` | *(planned)* `(violations / project_rules_count) Ã— rule_violation_penalty` |

Bus factor uses greedy approximation â€” never brute force in production.

### Layer 2 â€” Composite fragility score

```
busRisk = busFactor >= 5 ? 0 : max(0, 100 - busFactor * 20)

fragility = (
    busRisk        * w_bus_factor +
    uncoveredRatio * w_uncovered  +
    siloRatio      * w_silos      +
    absenceImpact  * w_absence
) / sum(weights)

fragility = min(100, fragility Ã— tolerance_factor) + rule_penalty   # rule_penalty: planned
fragility = clamp(0, 100)
```

The `tolerance_factor` reflects the organization's risk posture: `conservative = 1.2`, `balanced = 1.0`, `aggressive = 0.8`. The raw score maps to display tiers:

| Fragility | Tier |
|---|---|
| â‰¤ 20 | `solid` |
| â‰¤ 40 | `stable` |
| â‰¤ 60 | `stretched` |
| â‰¤ 80 | `fragile` |
| > 80 | `critical` |

### Layer 3 â€” Organization aggregates

- **Average fragility** across non-archived projects.
- **KCI (Knowledge Coverage Index)** per skill category â€” users at or above `kci_min_level` in the category, over all users with any skill in it.
- **Unique skill holders** â€” people who are the *only* holder of a skill org-wide.
- **User criticality** â€” a composite of unique skills held, silo participation, and the number of projects where this user pushes the bus factor below the critical threshold.

### Layer 4 â€” Simulation

A simulation is the same pipeline, re-run with a virtual absence roster injected, then diffed against live values. It **never** writes to source tables, and it reuses the live services via the `$absentUserIds` parameter on every metric method â€” `SkillCoverageService` also accepts `$presentUserIds` to force someone available and isolate one person's impact. One code path â€” duplicated formulas drift, and this codebase has the scar tissue to prove it.

---

## Project status

The metrics engine is **live**. `SkillCoverageService` builds the real coverage matrix (horizon-aware, with virtual absence and forced-presence rosters for simulations), and the calculators in `app/Metrics/Calculators/` â€” `BusFactorCalculator`, `FragilityCalculator`, `CriticalityCalculator`, `KnowledgeCoverageCalculator` â€” implement the math above. Every recalculation writes both the project's cache columns and a `metric_snapshots` row, so trend history captures each change.

Still in flight:

- **Rules engine** â€” the `rule_penalty` input to fragility is designed but not yet implemented; fragility currently runs on the four coverage-derived scalars.
- **A few recalculation triggers** â€” most mutations dispatch `RecalculateProjectMetricsJob` from their Manager, but a couple of dispatch points (skill category changes, skill-wide updates) are still marked `TODO` in the code.
- **Snapshot read API** â€” snapshots are written on every recalculation; the endpoints that serve trend lines to the frontend are not wired yet.

---

## Tech stack

| Layer | Choice | Notes |
|---|---|---|
| Framework | Laravel 12 | PHP 8.2+ |
| Auth | Laravel Sanctum | Token-based API auth |
| Querying | Spatie Query Builder | Filtering, sorting, pagination â€” one convention everywhere |
| Database | PostgreSQL (preferred) or MySQL | Schema fully migration-driven |
| Queue | Redis | All heavy computation runs async â€” required, not optional |
| Tests | PHPUnit 11 | `composer test` |
| Tooling | Pint, Sail, Vite | Linting, optional Docker, asset build |

The React frontend (React 19 + Vite + TanStack Query + ShadCN) lives in its own repository. This is the API.

---

## Architecture

Every domain entity flows through three strict layers, each with exactly one job:

```
HTTP Request
   â†“
Controller   â€” validates (FormRequest), converts Request â†’ QueryParams, returns Resources
   â†“
Manager      â€” orchestrates Services, owns DB transactions, dispatches jobs
   â†“
Service      â€” does the actual work: queries, model manipulation, data shaping
```

**Controller** is the only layer that knows HTTP exists. It never touches a model or writes a query.

**Manager** combines Services â€” one per entity â€” inside a single transaction when a mutation spans entities, and dispatches recalculation jobs after mutations. It never queries the database directly.

**Service** is strictly single-responsibility: one entity, one DB action per method. A method that "deletes A and B" is two methods, orchestrated by the Manager.

### The QueryParams boundary

`Illuminate\Http\Request` never crosses the Controller boundary. Paginated, filterable endpoints use the `QueryParams` DTO (`app/Support/QueryParams.php`): the Controller calls `QueryParams::fromRequest($request)`, the Service calls `$params->toRequest()` to feed Spatie Query Builder a synthetic request. Managers and Services stay fully HTTP-agnostic â€” which means they are trivially callable from jobs, commands, and tests.

### Error handling

No `abort()`, `abort_if()`, or `response()` below the Controller. Guards throw domain exceptions from `app/Exceptions/` (e.g. `InvalidCredentialsException`), and the exception handler maps them to HTTP status codes. Business logic raises business errors; HTTP semantics stay at the edge.

### Directory map

```
app/
â”œâ”€â”€ Http/Controllers/   13 controllers â€” thin, HTTP-only
â”œâ”€â”€ Managers/           13 managers â€” orchestration, transactions, job dispatch
â”œâ”€â”€ Services/           15 services â€” one per entity, incl. SkillCoverageService (Layer 0)
â”œâ”€â”€ Metrics/
â”‚   â”œâ”€â”€ Calculators/    BusFactor, Fragility, Criticality, KnowledgeCoverage (Layers 1â€“3)
â”‚   â”œâ”€â”€ Snapshots/      MetricSnapshot persistence â€” trend history per recalculation
â”‚   â””â”€â”€ Scales/         Tier mappings (fragility, bus factor)
â”œâ”€â”€ Models/             User, Project, Skill, SkillCategory, Department,
â”‚                       Absence, CompanyHoliday, OrganizationSetting
â”œâ”€â”€ Jobs/               RecalculateProjectMetricsJob (queued)
â”œâ”€â”€ DTO/                Structured stats payloads (ProjectStats, UserStats, ...)
â”œâ”€â”€ Enums/              UserStatus, ProjectStatus, AbsenceType, AbsenceHalf
â”œâ”€â”€ Exceptions/         Domain exceptions, mapped to HTTP by the handler
â””â”€â”€ Support/            QueryParams, AbsenceSlot, CompetencyRadar
database/
â”œâ”€â”€ migrations/         24 migrations â€” full schema, including pivots
â”œâ”€â”€ seeders/            11 seeders â€” realistic demo organization
â””â”€â”€ factories/          7 factories
routes/api.php          Explicit routes, grouped by entity
```

---

## Domain model

**Core entities:** `User`, `Skill`, `SkillCategory`, `Project`, `Department`, `Absence`, `CompanyHoliday`, `OrganizationSetting`.

Three pivot tables power every computation:

| Pivot | Meaning |
|---|---|
| `user_skills` | Who knows what, level 1â€“5 |
| `project_users` | Who is assigned where |
| `project_skill_reqs` | What each project requires, at which level |

The central join â€” `project_users â†’ user_skills â†’ project_skill_reqs` â€” produces the coverage matrix.

Worth knowing:

- **Absences are half-day aware.** Start/end halves (AM/PM), normalized to working days via `AbsenceNormalizer`, respecting company holidays and the configured working-day calendar.
- **Project status is derived**, not stored ad hoc: `Planned / Active / Paused / Completed / Archived`, with explicit lifecycle endpoints for each transition.
- **All tuning lives in one row.** Fragility weights, silo threshold, absence horizon, KCI minimum level, risk tolerance, rule violation penalty â€” all on `OrganizationSetting`. Adjusting the org's risk posture is a settings change, never a code change. There are no magic numbers in services.

---

## Recalculation system

Metrics are **never** computed synchronously in a request:

```
Manager mutation (skills / assignments / requirements / absences)
   â†’ RecalculateProjectMetricsJob (queued)
      â†’ ProjectManager::recalculateProjectMetrics(project)
         â†’ SkillCoverageService::getCoverage(project)
         â†’ BusFactorCalculator / FragilityCalculator
         â†’ Project cache columns + metric snapshot â€” one transaction
```

Controllers read the precomputed columns. The job is deliberately *not* unique-per-project: every dispatch produces a snapshot row, so the trend history records every change rather than the last one in a burst. This is why the queue worker is required, not optional â€” without it, recalculation jobs just pile up politely.

---

## API overview

All endpoints sit behind Sanctum â€” `POST /auth/login` returns a token. The surface, by neighborhood:

| Area | Endpoints |
|---|---|
| **Auth** | login, logout, current user |
| **Dashboard** | org-wide stats, knowledge coverage, upcoming risk events |
| **Projects** | CRUD Â· lifecycle (pause / resume / complete / reopen / archive / unarchive) Â· coverage matrix Â· competency radar Â· fragility alerts Â· team & skill-requirement management |
| **Users** | CRUD Â· criticality Â· capacity Â· recommendations Â· competency radar Â· skill attach/update/detach |
| **Absences** | CRUD, per-user listings and stats â€” half-day aware |
| **Planning** | monthly view Â· what-if simulation Â· apply |
| **Settings** | organization settings Â· calendar & working days Â· holidays Â· departments Â· skill categories (with KCI) Â· skills |

Query conventions are uniform across every list endpoint:

| Purpose | Format |
|---|---|
| Search | `?search=foo` or `?filter[search]=foo` |
| Exact filter | `?filter[category_id]=3` |
| Sort | `?sort=name` ascending, `?sort=-name` descending |
| Pagination | `?page=2&per_page=20` |

Routes are declared explicitly in `routes/api.php` â€” no `apiResource()` magic, every endpoint has a named, intention-revealing controller method.

---

## Getting started

**Prerequisites:** PHP 8.2+, Composer, Node.js, PostgreSQL or MySQL, Redis.

```bash
# 1. One-shot setup: install, .env, app key, migrate, npm install + build
composer setup

# 2. Configure your database and Redis connection in .env
#    (defaults in .env.example â€” DB_DATABASE=sentinel_backend)

# 3. Seed a realistic demo organization: people, skills, projects, absences
php artisan db:seed

# 4. Run everything: HTTP server + queue worker + Vite, concurrently
composer dev
```

```bash
# Run the test suite
composer test
```

Sail is available if you prefer Docker, but nothing requires it.

---

## Critical rules

1. **All metrics derive from skill coverage.** No exceptions, no side channels, no metric that bypasses the matrix.
2. **Simulation never alters real data.** Virtual rosters travel as parameters through the live pipeline â€” source tables are never touched.
3. **Bus factor uses greedy approximation.** Brute force is for whiteboards, not production.
4. **Logic stays deterministic and testable.** Same inputs, same fragility â€” always.

---

<div align="center">

**The end goal:** a system where a manager can **see** risk instantly, **understand** why it exists,
**simulate** changes, and **act** before failure happens â€” instead of discovering the hard way
that the entire deployment pipeline lived in one person's head.

</div>
