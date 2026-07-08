<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referees', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Computed profile columns, recomputed nightly by RecomputeProfilesJob.
            $table->unsignedSmallInteger('matches_officiated')->default(0);
            $table->decimal('avg_yellows_per_match', 5, 2)->nullable();
            $table->decimal('avg_reds_per_match', 5, 2)->nullable();
            $table->decimal('avg_fouls_per_match', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referees');
    }
};
