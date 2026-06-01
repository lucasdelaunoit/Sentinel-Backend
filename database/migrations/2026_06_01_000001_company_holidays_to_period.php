<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_holidays', function (Blueprint $table) {
            $table->dropUnique(['date', 'name']);
            $table->date('start_date')->nullable()->after('name');
            $table->date('end_date')->nullable()->after('start_date');
        });

        DB::table('company_holidays')->orderBy('id')->each(function ($row) {
            DB::table('company_holidays')
                ->where('id', $row->id)
                ->update([
                    'start_date' => $row->date,
                    'end_date'   => $row->date,
                ]);
        });

        Schema::table('company_holidays', function (Blueprint $table) {
            $table->date('start_date')->nullable(false)->change();
            $table->date('end_date')->nullable(false)->change();
            $table->dropColumn('date');
            $table->unique(['start_date', 'end_date', 'name']);
            $table->index('start_date');
            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('company_holidays', function (Blueprint $table) {
            $table->dropUnique(['start_date', 'end_date', 'name']);
            $table->dropIndex(['start_date']);
            $table->dropIndex(['end_date']);
            $table->date('date')->nullable()->after('name');
        });

        DB::table('company_holidays')->orderBy('id')->each(function ($row) {
            DB::table('company_holidays')
                ->where('id', $row->id)
                ->update(['date' => $row->start_date]);
        });

        Schema::table('company_holidays', function (Blueprint $table) {
            $table->date('date')->nullable(false)->change();
            $table->dropColumn(['start_date', 'end_date']);
            $table->unique(['date', 'name']);
        });
    }
};
