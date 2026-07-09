<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Work queue for the resumable player-stats backfill: one row per
    // finished fixture whose match report needs scraping. The nightly job
    // processes a batch and continues where it left off; newly finished
    // fixtures are enqueued by the same discovery step.
    public function up(): void
    {
        Schema::create('player_scrape_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('pending'); // pending|done|failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->dateTime('scraped_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'attempts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_scrape_progress');
    }
};
