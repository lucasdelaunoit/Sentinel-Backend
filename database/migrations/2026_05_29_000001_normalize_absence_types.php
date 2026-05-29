<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('absences')->whereIn('type', ['sick', 'personal'])->update(['type' => 'other']);
    }

    public function down(): void
    {
        // irreversible — original sick/personal distinction lost
    }
};
