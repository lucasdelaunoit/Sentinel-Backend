<?php

namespace App\Console\Commands;

use App\Managers\DashboardManager;
use App\Managers\ProjectManager;
use App\Managers\UserManager;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * TEMPORARY bootstrap command — runs the full metric cascade sync:
 *   1. Per-project recalc (cached cols + project snapshots)
 *   2. Per-user recalc   (cached cols + user snapshots)
 *   3. Org projects aggregates (org snapshots)
 *   4. Org users aggregates    (org snapshots)
 *   5. Org dashboard aggregates (org snapshots)
 *
 * Order matters: org-scope Calculators read cached cols populated in steps 1+2.
 * Remove once observers / scheduled jobs own this responsibility.
 */
class RecalcEverythingCommand extends Command
{
    protected $signature = 'sentinel:recalc-everything';
    protected $description = 'Run the full metric cascade synchronously (projects → users → org aggregates). Temporary bootstrap.';

    public function handle(
        ProjectManager $projectManager,
        UserManager $userManager,
        DashboardManager $dashboardManager,
    ): int {
        $this->info('[1/5] Recalculating per-project metrics...');
        $projects = Project::query()->whereNull('archived_at')->get();
        $this->withProgress($projects, fn(Project $p) => $projectManager->recalculateProjectMetrics($p));

        $this->info('[2/5] Recalculating per-user metrics...');
        $users = User::query()->get();
        $this->withProgress($users, fn(User $u) => $userManager->captureUserStatsSnapshots($u));

        $this->info('[3/5] Capturing org-scope projects aggregates...');
        $projectManager->captureProjectsStatsSnapshots();

        $this->info('[4/5] Capturing org-scope users aggregates...');
        $userManager->captureUsersStatsSnapshots();

        $this->info('[5/5] Capturing org-scope dashboard aggregates...');
        $dashboardManager->captureDashboardStatsSnapshots();

        $this->newLine();
        $this->info("Done. Projects: {$projects->count()} · Users: {$users->count()}");

        return self::SUCCESS;
    }

    private function withProgress(\Illuminate\Support\Collection $items, callable $fn): void
    {
        if ($items->isEmpty()) {
            $this->line('  (nothing to do)');
            return;
        }

        $bar = $this->output->createProgressBar($items->count());
        $bar->start();
        foreach ($items as $item) {
            $fn($item);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }
}
