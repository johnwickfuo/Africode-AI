<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // One row per TEAM per FINISHED fixture (primary source: FBref via soccerdata).
    public function up(): void
    {
        Schema::create('match_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_home');
            $table->unsignedTinyInteger('goals')->nullable();
            $table->decimal('xg', 5, 2)->nullable();
            $table->decimal('xga', 5, 2)->nullable();
            $table->unsignedSmallInteger('shots')->nullable();
            $table->unsignedSmallInteger('shots_on_target')->nullable();
            $table->unsignedSmallInteger('shots_on_target_against')->nullable();
            $table->unsignedSmallInteger('corners_for')->nullable();
            $table->unsignedSmallInteger('corners_against')->nullable();
            $table->unsignedSmallInteger('crosses')->nullable();
            $table->unsignedSmallInteger('fouls_committed')->nullable();
            $table->unsignedSmallInteger('fouls_drawn')->nullable();
            $table->unsignedTinyInteger('yellows')->nullable();
            $table->unsignedTinyInteger('reds')->nullable();
            $table->decimal('possession', 5, 2)->nullable();
            $table->string('source', 16)->default('fbref'); // fbref|apifootball
            $table->timestamps();

            $table->unique(['fixture_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_stats');
    }
};
