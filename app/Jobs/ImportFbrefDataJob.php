<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\Fbref\ImportFbrefDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nightly (02:45 Africa/Lagos, after ScrapeFbrefJob): parses
 * storage/app/pipeline/fbref_latest.json and upserts match_stats,
 * fixtures (results), and referees. Idempotent — if tonight's scrape
 * failed it simply re-imports the last good file.
 */
class ImportFbrefDataJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // local + deterministic: a retry would fail identically

    public int $timeout = 1800;

    public function handle(ImportFbrefDataService $import): void
    {
        PipelineRun::track('ImportFbrefDataJob', fn () => $import->run());
    }
}
