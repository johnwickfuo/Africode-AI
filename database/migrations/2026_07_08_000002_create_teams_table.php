<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('fbref_name'); // squad name as it appears in FBref/soccerdata tables
            // Filled by the football-data.org fixture sync (build order step 2),
            // matched by name — free-tier team ids are not seeded statically.
            $table->unsignedInteger('footballdata_id')->nullable()->unique();
            $table->unsignedInteger('apifootball_id')->nullable();
            $table->string('short_name', 32);
            $table->string('logo_url')->nullable();
            $table->timestamps();

            $table->unique(['league_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
