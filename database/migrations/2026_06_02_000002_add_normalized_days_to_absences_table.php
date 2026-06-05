<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            // Working-day count (weekends + holidays excluded), half-aware.
            // Hybrid policy: recomputed live while the absence is upcoming, then
            // frozen once it starts. normalized_frozen_at marks the freeze.
            // total_days (raw calendar span) stays derived — no column needed.
            $table->decimal('normalized_days', 5, 1)->nullable()->after('end_half');
            $table->timestamp('normalized_frozen_at')->nullable()->after('normalized_days');
        });
    }

    public function down(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            $table->dropColumn(['normalized_days', 'normalized_frozen_at']);
        });
    }
};
