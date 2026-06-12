<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 32);                   // project | user | org
            $table->unsignedBigInteger('scope_id')->nullable(); // null when scope_type = 'org'
            $table->string('status', 16)->default('queued');    // queued | running | completed | failed
            $table->unsignedInteger('total_items')->default(1);
            $table->unsignedInteger('processed_items')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id', 'status'], 'calculation_runs_scope_status_idx');
            $table->index(['scope_type', 'scope_id', 'finished_at'], 'calculation_runs_scope_finished_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculation_runs');
    }
};
