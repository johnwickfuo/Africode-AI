<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per tier per generation run (tiers: 3x ... 10000x).
        Schema::create('accumulators', function (Blueprint $table) {
            $table->id();
            $table->dateTime('generated_at');
            $table->unsignedInteger('target_odds');
            $table->decimal('combined_odds', 10, 2);
            $table->decimal('combined_probability', 10, 8);
            $table->unsignedTinyInteger('legs_count');
            $table->string('outcome', 8)->default('pending'); // pending|won|lost|void
            $table->dateTime('settled_at')->nullable();
            $table->timestamps();

            $table->index(['generated_at', 'target_odds']);
            $table->index('outcome');
        });

        Schema::create('accumulator_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accumulator_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prediction_market_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            // Denormalized from the market row so legs render without joins
            // and stay readable even if predictions regenerate.
            $table->string('market', 32);
            $table->decimal('line', 4, 1)->nullable();
            $table->string('direction', 8);
            $table->decimal('probability', 5, 4);
            $table->decimal('odds', 6, 3); // fair odds = 1 / probability
            $table->timestamps();

            $table->index('accumulator_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accumulator_legs');
        Schema::dropIfExists('accumulators');
    }
};
