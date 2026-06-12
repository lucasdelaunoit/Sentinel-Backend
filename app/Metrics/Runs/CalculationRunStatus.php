<?php

namespace App\Metrics\Runs;

/**
 * Lifecycle of one calculation_runs row.
 *  - Queued: dispatch accepted, job waiting (debounce delay or queue backlog).
 *  - Running: job picked up, calculators executing.
 *  - Completed / Failed: terminal — finished_at set, Failed also stores the error.
 */
enum CalculationRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
