<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // Cached metrics — populated by RecalculateProjectRiskJob
            $table->unsignedTinyInteger('fragility_raw')->default(0);
            $table->unsignedSmallInteger('bus_factor')->default(0);

            // Lifecycle timestamps — status is derived from these
            $table->date('started_at')->nullable();
            $table->date('deadline')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
