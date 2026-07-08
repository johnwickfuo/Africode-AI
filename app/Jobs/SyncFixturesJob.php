<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\FootballData\FixtureSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nightly (03:00 Africa/Lagos) football-data.org sync: fixtures for the next
 * 14 days plus results for the last 3 days across all five leagues. External
 * HTTP happens only here, on the queue — never in a web request.
 */
class SyncFixturesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds between queue-level retries */
    public array $backoff = [60, 300];

    // 5 leagues x (1 request + 7 s throttle) plus HTTP retries — generous cap.
    public int $timeout = 600;

    public function handle(FixtureSyncService $sync): void
    {
        PipelineRun::track('SyncFixturesJob', fn () => $sync->run());
    }
}
