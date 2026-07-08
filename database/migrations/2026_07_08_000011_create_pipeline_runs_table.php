<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Log of scheduled pipeline jobs; last-run status is surfaced in the UI footer.
    public function up(): void
    {
        Schema::create('pipeline_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job_name', 64);
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('status', 12)->default('running'); // running|success|failed
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['job_name', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_runs');
    }
};
