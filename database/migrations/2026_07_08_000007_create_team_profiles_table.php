<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Rolling aggregates recomputed nightly; exponentially weighted
    // (half-life ~8 matches). One row per team per season.
    public function up(): void
    {
        Schema::create('team_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('season', 9);
            $table->unsignedSmallInteger('matches_played')->default(0);
            $table->decimal('attack_strength', 8, 4)->nullable();  // Dixon-Coles parameter
            $table->decimal('defence_strength', 8, 4)->nullable(); // Dixon-Coles parameter
            $table->decimal('xg_for_avg', 6, 3)->nullable();
            $table->decimal('xg_against_avg', 6, 3)->nullable();
            $table->decimal('corners_for_avg', 6, 3)->nullable();
            $table->decimal('corners_against_avg', 6, 3)->nullable();
            $table->decimal('crosses_avg', 6, 3)->nullable();
            $table->decimal('cards_avg', 6, 3)->nullable();
            $table->decimal('fouls_committed_avg', 6, 3)->nullable();
            $table->decimal('fouls_drawn_avg', 6, 3)->nullable();
            $table->decimal('sot_for_avg', 6, 3)->nullable();
            $table->decimal('sot_against_avg', 6, 3)->nullable();
            $table->decimal('home_advantage_factor', 8, 4)->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'season']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_profiles');
    }
};
