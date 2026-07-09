<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\Accas\AccumulatorBuilderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily (06:30 Africa/Lagos, after GeneratePredictionsJob): builds the
 * day's accumulator set from the fresh predictions. Local computation only.
 */
class GenerateAccumulatorsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(AccumulatorBuilderService $builder): void
    {
        PipelineRun::track('GenerateAccumulatorsJob', fn () => $builder->run());
    }
}
