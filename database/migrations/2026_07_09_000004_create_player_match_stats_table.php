<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // One row per player per fixture (FBref match report "summary" table).
    public function up(): void
    {
        Schema::create('player_match_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            // The team the player appeared for IN THIS MATCH — unlike
            // players.team_id this never changes, so per-club season totals
            // stay accurate through mid-season transfers.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('minutes')->nullable();
            $table->unsignedTinyInteger('goals')->nullable();
            $table->unsignedTinyInteger('assists')->nullable();
            $table->unsignedTinyInteger('shots')->nullable();
            $table->unsignedTinyInteger('shots_on_target')->nullable();
            $table->unsignedTinyInteger('yellows')->nullable();
            $table->unsignedTinyInteger('reds')->nullable();
            $table->decimal('xg', 4, 2)->nullable();
            $table->decimal('xa', 4, 2)->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'fixture_id']);
            $table->index(['team_id', 'fixture_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_match_stats');
    }
};
