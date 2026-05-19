<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\RiskCalculationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recompute cached risk columns for a project. Debounced via ShouldBeUnique
 * so rapid mutations on the same project collapse into a single execution
 * within the uniqueFor window.
 */
class RecalculateProjectRiskJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries     = 3;
    public int $uniqueFor = 30;

    public function __construct(
        public readonly Project $project,
    ) {}

    public function uniqueId(): string
    {
        return 'project:' . $this->project->id;
    }

    public function handle(RiskCalculationService $risk): void
    {
        $project = $this->project->fresh();
        if ($project === null) return;

        $project->update([
            'bus_factor'     => $risk->computeBusFactor($project),
            'fragility_raw'  => (int) round($risk->computeFragilityRaw($project)),
        ]);
    }
}
