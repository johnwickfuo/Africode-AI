<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Materialized accuracy summary, rebuilt nightly by SettlePredictionsJob.
    public function up(): void
    {
        Schema::create('model_accuracy', function (Blueprint $table) {
            $table->id();
            $table->string('market', 32);
            $table->string('line_bucket', 16); // e.g. "2.5", "9.5", "yes"
            $table->unsignedInteger('total_settled')->default(0);
            $table->unsignedInteger('hits')->default(0);
            $table->decimal('hit_rate', 5, 4)->nullable();
            $table->decimal('avg_probability', 5, 4)->nullable();
            $table->decimal('calibration_gap', 6, 4)->nullable(); // signed: avg_probability - hit_rate
            $table->timestamps();

            $table->unique(['market', 'line_bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_accuracy');
    }
};
