<div align="center">

<img src="public/logo.svg" alt="Sentinel logo" width="96" />

# Sentinel

**Skill-based organizational risk analyzer — the API**

*Who can your team afford to lose? Find out before it hurts.*

![Laravel](https://img.shields.io/badge/Laravel_12-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP_8.2%2B-777BB4?logo=php&logoColor=white)
![Sanctum](https://img.shields.io/badge/Sanctum-FF2D20?logo=laravel&logoColor=white)
![Redis](https://img.shields.io/badge/Redis-DC382D?logo=redis&logoColor=white)
![Status](https://img.shields.io/badge/metrics_engine-rebuilding-orange)

</div>

---

## Table of Contents

- [Why Sentinel](#why-sentinel)
- [The Mental Model](#the-mental-model)
- [The Metrics Pipeline](#the-metrics-pipeline)
- [Project Status](#project-status)
- [Tech Stack](#tech-stack)
- [Architecture](#architecture)
- [Domain Model](#domain-model)
- [Recalculation System](#recalculation-system)
- [API Overview](#api-overview)
- [Getting Started](#getting-started)
- [Conventions](#conventions)
- [Critical Rules](#critical-rules)

---

## Why Sentinel

Every team has them: the one person who knows how the deployment pipeline works, the only engineer who can touch the billing code, the architect whose two-week vacation quietly freezes three projects. Organizations rarely see these single points of failure until they fail.

Sentinel makes them visible. It answers the questions every manager quietly worries about:

- *If Sarah leaves tomorrow, which projects catch fire?*
- *How many people actually understand this system?* (Spoiler: it's usually one.)
- *Can we survive August?*

It does this by modeling the organization as a graph of **skills**, **people**, and **projects** — and deriving every risk metric from that graph. No gut feeling, no survey, no spreadsheet archaeology. Just the data you already have about who knows what.

---

## The Mental Model

Sentinel is not a CRUD app wearing a dashboard. It is a deterministic pipeline:

```
INPUT DATA → COVERAGE MATRIX → DERIVED STATES → METRICS → DECISIONS
```

The heart of the system is the **Skill Coverage Matrix**. For every project, for every required skill: who can cover it, at what level — and are they even around within the planning horizon?

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

Everything downstream is a function of this matrix. If coverage is correct, everything is correct. No metric bypasses it — that is the system's one non-negotiable invariant.

---

## The Metrics Pipeline

Five layers, each consuming only the layer below. Four inputs feed the whole thing: organization settings (weights, thresholds, horizons), project assignments and skill requirements, user skills and absences, and enabled rules.

### Layer 0 — Coverage Matrix

For each required skill on a project:

1. Filter assigned users to those **not absent** within `absence_horizon_days`.
2. Keep users whose skill level meets `required_level`.
3. Resolve status: `0` covering → `uncovered` · `≤ silo_threshold` → `siloed` · otherwise → `safe`.

### Layer 1 — Atomic Project Metrics

| Metric | Definition |
|---|---|
| `bus_factor` | Greedy set-cover minimum: smallest user set whose removal leaves at least one skill uncovered |
| `uncovered_ratio` | `count(uncovered) / count(required)` |
| `silo_ratio` | `count(siloed) / count(required)` |
| `absence_impact` | Skills that become uncovered once horizon absences apply, over total required |
| `rule_penalty` | `(violations / project_rules_count) × rule_violation_penalty` |

Bus factor uses greedy approximation — never brute force in production.

### Layer 2 — Composite Fragility Score

```
busRisk = busFactor >= 5 ? 0 : max(0, 100 - busFactor * 20)

fragility = (
    busRisk        * w_bus_factor +
    uncoveredRatio * w_uncovered  +
    siloRatio      * w_silos      +
    absenceImpact  * w_absence
) / sum(weights)

fragility = min(100, fragility × tolerance_factor) + rule_penalty
fragility = clamp(0, 100)
```

The `tolerance_factor` reflects the organization's risk posture: `conservative = 1.2`, `balanced = 1.0`, `aggressive = 0.8`. The raw score maps to display tiers:

| Fragility | Tier |
|---|---|
| ≤ 20 | `solid` |
| ≤ 40 | `stable` |
| ≤ 60 | `stretched` |
| ≤ 80 | `fragile` |
| > 80 | `critical` |

### Layer 3 — Organization Aggregates

- **Average fragility** across non-archived projects.
- **KCI (Knowledge Coverage Index)** per skill category — users at or above `kci_min_level` in the category, over all users with any skill in it.
- **Unique skill holders** — people who are the *only* holder of a skill org-wide.
- **User criticality** — a composite of unique skills held, silo participation, and the number of projects where this user pushes the bus factor below the critical threshold.

### Layer 4 — Simulation

A simulation is the same pipeline, re-run with a virtual absence roster injected, then diffed against live values. It **must never** write to source tables, and it **must** reuse the live services via an `?absentUserIds = []` parameter on every metric method. One code path — duplicated formulas drift, and this codebase has the scar tissue to prove it.

---

## Project Status

> **The calculation engine is currently stubbed.**
> `SkillCoverageService`, `RiskCalculationService`, and `RecalculateProjectRiskJob` return hardcoded realistic values so the rest of the system stays compilable while the real engine is rebuilt from scratch. The API surface, domain model, seeders, and architecture are live; the math described above is the **target design**, not what runs today.
>
> Grep `TODO: real implementation` to find every stub. Model observers that trigger recalculation are designed but not yet wired.

---

## Tech Stack

| Layer | Choice | Notes |
|---|---|---|
| Framework | Laravel 12 | PHP 8.2+ |
| Auth | Laravel Sanctum | Token-based API auth |
| Querying | Spatie Query Builder | Filtering, sorting, pagination — one convention everywhere |
| Database | PostgreSQL (preferred) or MySQL | Schema fully migration-driven |
| Queue | Redis | All heavy computation runs async — required, not optional |
| Tests | PHPUnit 11 | `composer test` |
| Tooling | Pint, Sail, Vite | Linting, optional Docker, asset build |

The React frontend (Vite + SWR + ShadCN) lives in its own repository. This is the API.

---

## Architecture

Every domain entity flows through three strict layers, each with exactly one job:

```
HTTP Request
   ↓
Controller   — validates (FormRequest), converts Request → QueryParams, returns Resources
   ↓
Manager      — orchestrates Services, owns DB transactions, dispatches jobs
   ↓
Service      — does the actual work: queries, model manipulation, data shaping
```

**Controller** is the only layer that knows HTTP exists. It never touches a model or writes a query.

**Manager** combines Services — one per entity — inside a single transaction when a mutation spans entities, and dispatches recalculation jobs after mutations. It never queries the database directly.

**Service** is strictly single-responsibility: one entity, one DB action per method. A method that "deletes A and B" is two methods, orchestrated by the Manager.

### The QueryParams boundary

`Illuminate\Http\Request` never crosses the Controller boundary. Paginated, filterable endpoints use the `QueryParams` DTO (`app/Support/QueryParams.php`): the Controller calls `QueryParams::fromRequest($request)`, the Service calls `$params->toRequest()` to feed Spatie Query Builder a synthetic request. Managers and Services stay fully HTTP-agnostic — which means they are trivially callable from jobs, commands, and tests.

### Error handling

No `abort()`, `abort_if()`, or `response()` below the Controller. Guards throw domain exceptions from `app/Exceptions/` (e.g. `InvalidCredentialsException`), and the exception handler maps them to HTTP status codes. Business logic raises business errors; HTTP semantics stay at the edge.

### Directory map

```
app/
├── Http/Controllers/   13 controllers — thin, HTTP-only
├── Managers/           13 managers — orchestration, transactions, job dispatch
├── Services/           15 services — one per entity + calculation engine
├── Models/             User, Project, Skill, SkillCategory, Department,
│                       Absence, CompanyHoliday, OrganizationSetting
├── Jobs/               RecalculateProjectRiskJob (queued, debounced)
├── Enums/              UserStatus, ProjectStatus, AbsenceType, AbsenceHalf
├── Exceptions/         Domain exceptions, mapped to HTTP by the handler
└── Support/            QueryParams, AbsenceSlot, CompetencyRadar
database/
├── migrations/         24 migrations — full schema, including pivots
├── seeders/            11 seeders — realistic demo organization
└── factories/          7 factories
routes/api.php          Explicit routes, grouped by entity
CLAUDE.md               Full engineering specification — read before touching metrics
```

---

## Domain Model

**Core entities:** `User`, `Skill`, `SkillCategory`, `Project`, `Department`, `Absence`, `CompanyHoliday`, `OrganizationSetting`.

Three pivot tables power every computation:

| Pivot | Meaning |
|---|---|
| `user_skills` | Who knows what, level 1–5 |
| `project_users` | Who is assigned where |
| `project_skill_reqs` | What each project requires, at which level |

The central join — `project_users → user_skills → project_skill_reqs` — produces the coverage matrix.

Worth knowing:

- **Absences are half-day aware.** Start/end halves (AM/PM), normalized to working days via `AbsenceNormalizer`, respecting company holidays and the configured working-day calendar.
- **Project status is derived**, not stored ad hoc: `Planned / Active / Paused / Completed / Archived`, with explicit lifecycle endpoints for each transition.
- **All tuning lives in one row.** Fragility weights, silo threshold, absence horizon, KCI minimum level, risk tolerance, rule violation penalty — all on `OrganizationSetting`. Adjusting the org's risk posture is a settings change, never a code change. There are no magic numbers in services.

---

## Recalculation System

Metrics are **never** computed synchronously in a request. The target flow:

```
Model Observer (skills / assignments / requirements / absences change)
   → RecalculateProjectRiskJob (queued, debounced)
      → SkillCoverageService::getCoverage(project)
      → RiskCalculationService::computeBusFactor / computeFragilityRaw
      → Project::update([bus_factor, fragility_raw])
```

Controllers read the precomputed columns. A change to `organization_settings` triggers a bulk recalculation of all projects. This is why the queue worker is required, not optional — without it, recalculation jobs just pile up politely.

---

## API Overview

All endpoints sit behind Sanctum — `POST /auth/login` returns a token. The surface, by neighborhood:

| Area | Endpoints |
|---|---|
| **Auth** | login, logout, current user |
| **Dashboard** | org-wide stats, knowledge coverage, upcoming risk events |
| **Projects** | CRUD · lifecycle (pause / resume / complete / reopen / archive / unarchive) · coverage matrix · competency radar · fragility alerts · team & skill-requirement management |
| **Users** | CRUD · criticality · capacity · recommendations · competency radar · skill attach/update/detach |
| **Absences** | CRUD, per-user listings and stats — half-day aware |
| **Planning** | monthly view · what-if simulation · apply |
| **Settings** | organization settings · calendar & working days · holidays · departments · skill categories (with KCI) · skills |

Query conventions are uniform across every list endpoint:

| Purpose | Format |
|---|---|
| Search | `?search=foo` or `?filter[search]=foo` |
| Exact filter | `?filter[category_id]=3` |
| Sort | `?sort=name` ascending, `?sort=-name` descending |
| Pagination | `?page=2&per_page=20` |

Routes are declared explicitly in `routes/api.php` — no `apiResource()` magic, every endpoint has a named, intention-revealing controller method.

---

## Getting Started

**Prerequisites:** PHP 8.2+, Composer, Node.js, PostgreSQL or MySQL, Redis.

```bash
# 1. One-shot setup: install, .env, app key, migrate, npm install + build
composer setup

# 2. Configure your database and Redis connection in .env
#    (defaults in .env.example — DB_DATABASE=sentinel_backend)

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

## Conventions

A few house rules that make the codebase predictable:

- **Explicit verb+noun method names, everywhere.** `getAgileUsers`, `attachSkillToUser`, `deleteSkillCategory` — never `index`, `store`, `destroy`. The Controller / Manager / Service trio shares the exact same method name, including the full entity noun (no `deleteCategory` shorthand).
- **Every public Manager and Service method carries a docblock** in the project format, stating side effects (cascade, dispatch, transaction) explicitly. `@throws Throwable` is mandatory on any transactional Manager method.
- **Controller bodies are sectioned** with `// Act (Manager)` and `// Return (Controller)` comments (plus `// Validate & authorize` when guard logic exists beyond the FormRequest).
- **No column-alignment padding.** One space around `=` and `=>`, always — aligned columns rot into whitespace-storm diffs.
- **Eager loading by default.** N+1 queries are treated as bugs.

The complete specification — including canonical code examples for every pattern above — lives in [`CLAUDE.md`](CLAUDE.md).

---

## Critical Rules

1. **All metrics derive from skill coverage.** No exceptions, no side channels, no metric that bypasses the matrix.
2. **Simulation never alters real data.** Results live on the simulation record only.
3. **Bus factor uses greedy approximation.** Brute force is for whiteboards, not production.
4. **Logic stays deterministic and testable.** Same inputs, same fragility — always.

---

<div align="center">

**The end goal:** a system where a manager can **see** risk instantly, **understand** why it exists,
**simulate** changes, and **act** before failure happens — instead of discovering the hard way
that the entire deployment pipeline lived in one person's head.

</div>
