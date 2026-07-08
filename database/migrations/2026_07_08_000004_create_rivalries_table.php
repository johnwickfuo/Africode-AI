<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Static derby/rivalry list per league. Fixture sync marks fixtures.is_derby
    // when the fixture's team pair matches a row here (in either order).
    public function up(): void
    {
        Schema::create('rivalries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team1_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('team2_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name'); // e.g. "North London Derby"
            $table->timestamps();

            $table->unique(['team1_id', 'team2_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rivalries');
    }
};
