<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the legacy `simulations` / `simulation_absences` tables. The saved-simulation
 * resource was superseded by the live planning what-if engine (PlanningService::simulate),
 * which persists nothing until a scenario is applied as real Absence rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('simulation_absences');
        Schema::dropIfExists('simulations');
    }

    public function down(): void
    {
        // Legacy tables intentionally not recreated — feature removed.
    }
};
