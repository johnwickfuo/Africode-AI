<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_odds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_id')->unique()->constrained()->cascadeOnDelete();
            // Market-average decimal odds from football-data.co.uk (Avg*
            // columns; Bet365 fallback). 1X2 plus over/under 2.5 goals.
            $table->decimal('home_odds', 6, 2)->nullable();
            $table->decimal('draw_odds', 6, 2)->nullable();
            $table->decimal('away_odds', 6, 2)->nullable();
            $table->decimal('over25_odds', 6, 2)->nullable();
            $table->decimal('under25_odds', 6, 2)->nullable();
            $table->string('source', 16)->default('fdcouk');
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_odds');
    }
};
