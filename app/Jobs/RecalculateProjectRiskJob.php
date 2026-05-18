<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\RiskCalculationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateProjectRiskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly Project $project,
    ) {}

    public function handle(RiskCalculationService $risk): void
    {
        $this->project->update([
            'bus_factor'     => $risk->computeBusFactor($this->project),
            'fragility_raw'  => $risk->computeFragilityRaw($this->project),
            'trajectory_raw' => $risk->computeTrajectoryRaw($this->project),
        ]);
    }
}
