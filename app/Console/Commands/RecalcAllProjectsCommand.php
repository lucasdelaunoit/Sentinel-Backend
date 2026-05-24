<?php

namespace App\Console\Commands;

use App\Jobs\RecalculateProjectRiskJob;
use App\Models\Project;
use Illuminate\Console\Command;

class RecalcAllProjectsCommand extends Command
{
    protected $signature   = 'sentinel:recalc-all {--queue : Dispatch to queue instead of running sync}';
    protected $description = 'Recompute fragility / team_availability / knowledge_coverage for every non-archived project. Runs sync by default.';

    public function handle(): int
    {
        $projects = Project::query()->whereNull('archived_at')->get(['id', 'name', 'started_at', 'deadline', 'paused_at', 'completed_at', 'archived_at', 'fragility_raw', 'team_availability_raw', 'knowledge_coverage_raw']);
        $useQueue = (bool) $this->option('queue');

        if ($projects->isEmpty()) {
            $this->info('No projects to recalc.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($projects->count());
        $bar->start();

        foreach ($projects as $project) {
            $useQueue
                ? RecalculateProjectRiskJob::dispatch($project)
                : RecalculateProjectRiskJob::dispatchSync($project);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info(($useQueue ? 'Queued' : 'Recalculated') . ' ' . $projects->count() . ' projects.');

        return self::SUCCESS;
    }
}
