<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_settings', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('name');
            $table->string('industry')->nullable();
            $table->string('size')->nullable();
            $table->string('location')->nullable();

            // Operational profile
            $table->string('methodology')->default('agile');
            $table->string('team_structure')->default('cross-functional');
            $table->string('risk_tolerance')->default('balanced');

            // Calendar
            $table->json('working_days')->nullable();
            $table->string('timezone')->nullable();
            $table->unsignedTinyInteger('standard_days_month')->default(22);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_settings');
    }
};
