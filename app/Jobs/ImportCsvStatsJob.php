<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\FootballDataCoUk\CsvStatsImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nightly (02:30 Africa/Lagos): refreshes match statistics from
 * football-data.co.uk's free CSVs — plain downloads that work from any
 * IP. This keeps corners/cards/shots data flowing even when FBref is
 * unreachable; FBref rows, when present, are never overwritten.
 */
class ImportCsvStatsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function handle(CsvStatsImportService $import): void
    {
        PipelineRun::track('ImportCsvStatsJob', fn () => $import->run());
    }
}
