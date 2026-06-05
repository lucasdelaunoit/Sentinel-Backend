<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            // Which half of the boundary dates the absence covers. Defaults make
            // existing rows behave as full days (morning → afternoon).
            $table->string('start_half')->default('morning')->after('start_date'); // App\Enums\AbsenceHalf
            $table->string('end_half')->default('afternoon')->after('end_date');   // App\Enums\AbsenceHalf
        });
    }

    public function down(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            $table->dropColumn(['start_half', 'end_half']);
        });
    }
};
