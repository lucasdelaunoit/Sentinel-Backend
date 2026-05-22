<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 32);                // project | user | org | skill_category
            $table->unsignedBigInteger('scope_id')->nullable(); // null when scope_type = 'org'
            $table->string('metric', 64);                    // fragility | bus_factor | ...
            $table->decimal('value_raw', 8, 2);              // numeric form — drives trend math
            $table->string('value_label')->nullable();       // cached display label
            $table->string('severity', 16);                  // ok | warning | critical
            $table->json('meta')->nullable();                // insight string + any extras
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index('captured_at');
            $table->index(['scope_type', 'scope_id', 'metric', 'captured_at'], 'metric_snapshots_scope_metric_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_snapshots');
    }
};
