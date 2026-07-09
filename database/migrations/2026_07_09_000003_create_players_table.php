<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            // Current team. Mid-season transfers move this pointer forward
            // (updated from the newest match seen); per-match history keeps
            // its own team reference in player_match_stats.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('fbref_id', 16)->nullable()->unique();
            $table->string('position', 16)->nullable();
            $table->string('nationality', 8)->nullable();
            // Kickoff of the newest match imported for this player — the
            // guard that stops an older backfill batch regressing team_id.
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'nationality']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
