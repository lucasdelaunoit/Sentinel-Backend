<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');            // bus_factor | min_skill | min_coverage | role_redundancy
            $table->string('scope_type');      // organization | project | department
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->json('params');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rules');
    }
};
