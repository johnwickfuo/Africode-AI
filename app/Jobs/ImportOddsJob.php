<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\Odds\ImportOddsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily (05:45 Africa/Lagos, just before prediction generation): pulls
 * bookmaker odds for upcoming fixtures from football-data.co.uk's free
 * fixtures.csv, powering the value-bet comparison on the site.
 */
class ImportOddsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(ImportOddsService $import): void
    {
        PipelineRun::track('ImportOddsJob', fn () => $import->run());
    }
}
