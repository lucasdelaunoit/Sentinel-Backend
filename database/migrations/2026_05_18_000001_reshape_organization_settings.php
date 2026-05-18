<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_settings', function (Blueprint $table) {
            // Drop decorative + unused fields
            $table->dropColumn([
                'industry',
                'size',
                'location',
                'methodology',
                'team_structure',
                'timezone',
                'standard_days_month',
            ]);
        });

        Schema::table('organization_settings', function (Blueprint $table) {
            // Fragility weights — feed RiskCalculationService::computeFragilityRaw
            $table->unsignedTinyInteger('fragility_weight_bus_factor')->default(35);
            $table->unsignedTinyInteger('fragility_weight_uncovered_skills')->default(30);
            $table->unsignedTinyInteger('fragility_weight_silos')->default(20);
            $table->unsignedTinyInteger('fragility_weight_absence_impact')->default(15);

            // Thresholds
            $table->unsignedTinyInteger('silo_threshold')->default(1);
            $table->unsignedTinyInteger('kci_min_level')->default(3);
            $table->unsignedTinyInteger('critical_bus_factor_threshold')->default(2);

            // Trajectory blend (fragility share vs progress share, sum=100)
            $table->unsignedTinyInteger('trajectory_fragility_weight')->default(70);

            // Absence look-ahead window for absence_impact
            $table->unsignedSmallInteger('absence_horizon_days')->default(14);

            // Per-rule-violation drag on day-health
            $table->unsignedTinyInteger('rule_violation_penalty')->default(15);

            // fragility_tolerance already present as string from base migration.
        });
    }

    public function down(): void
    {
        Schema::table('organization_settings', function (Blueprint $table) {
            $table->dropColumn([
                'fragility_weight_bus_factor',
                'fragility_weight_uncovered_skills',
                'fragility_weight_silos',
                'fragility_weight_absence_impact',
                'silo_threshold',
                'kci_min_level',
                'critical_bus_factor_threshold',
                'trajectory_fragility_weight',
                'absence_horizon_days',
                'rule_violation_penalty',
            ]);

            $table->string('industry')->nullable();
            $table->string('size')->nullable();
            $table->string('location')->nullable();
            $table->string('methodology')->default('agile');
            $table->string('team_structure')->default('cross-functional');
            $table->string('timezone')->nullable();
            $table->unsignedTinyInteger('standard_days_month')->default(22);
        });
    }
};
