<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained()->cascadeOnDelete();
            $table->string('season', 9); // e.g. "2025-2026"
            $table->unsignedSmallInteger('matchday')->nullable();
            $table->foreignId('home_team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('away_team_id')->constrained('teams')->cascadeOnDelete();
            $table->dateTime('kickoff_utc');
            $table->string('status', 12)->default('scheduled'); // scheduled|finished|postponed
            $table->foreignId('referee_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('footballdata_match_id')->nullable()->unique();
            $table->unsignedTinyInteger('home_goals')->nullable();
            $table->unsignedTinyInteger('away_goals')->nullable();
            $table->boolean('is_derby')->default(false); // seeded from the static rivalry list
            $table->timestamps();

            $table->index(['league_id', 'season']);
            $table->index(['status', 'kickoff_utc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixtures');
    }
};
