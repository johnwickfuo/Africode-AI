<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Every line evaluated for a prediction run.
    public function up(): void
    {
        Schema::create('prediction_markets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_id')->constrained()->cascadeOnDelete();
            // goals|corners|cards|shots_on_target|btts|team_goals_home|team_goals_away
            $table->string('market', 32);
            $table->decimal('line', 4, 1)->nullable(); // null for btts
            $table->string('direction', 8); // over|under|yes|no
            $table->decimal('probability', 5, 4);
            $table->decimal('confidence_margin', 5, 4); // abs(probability - 0.5)
            $table->string('outcome', 8)->default('pending'); // pending|won|lost|void
            $table->dateTime('settled_at')->nullable();
            $table->timestamps();

            $table->index(['market', 'outcome']);
            $table->index(['prediction_id', 'market']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_markets');
    }
};
