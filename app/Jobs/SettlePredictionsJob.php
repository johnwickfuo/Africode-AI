<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\Predictions\SettlePredictionsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nightly (03:30 Africa/Lagos, after results and stats have landed):
 * settles pending prediction markets and refreshes model_accuracy.
 */
class SettlePredictionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function handle(SettlePredictionsService $settlement): void
    {
        PipelineRun::track('SettlePredictionsJob', fn () => $settlement->run());
    }
}
