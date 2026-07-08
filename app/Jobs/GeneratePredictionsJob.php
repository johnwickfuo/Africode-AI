<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\Predictions\GeneratePredictionsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily (06:00 Africa/Lagos, after the whole nightly data pipeline):
 * exports model inputs, runs scripts/predict.py for all fixtures in the
 * next 7 days, and imports the predictions. Local computation only.
 */
class GeneratePredictionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function handle(GeneratePredictionsService $predictions): void
    {
        PipelineRun::track('GeneratePredictionsJob', fn () => $predictions->run());
    }
}
