<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // One row per fixture per generation run.
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $table->dateTime('generated_at');
            $table->string('model_version', 32);
            $table->string('best_bet_market', 32);
            $table->decimal('best_bet_line', 4, 1)->nullable(); // null for line-less markets (btts)
            $table->string('best_bet_direction', 8); // over|under|yes|no
            $table->decimal('best_bet_probability', 5, 4);
            $table->string('headline_text');
            $table->timestamps();

            $table->index(['fixture_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
