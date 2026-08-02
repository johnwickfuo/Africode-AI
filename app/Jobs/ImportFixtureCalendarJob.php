<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\Fixtures\FixtureCalendarImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily (02:50 Africa/Lagos, ahead of the fixture sync): pulls the full
 * published season calendar for the leagues football-data.org's free tier
 * does not carry, so they show the same 14-day horizon as the rest.
 */
class ImportFixtureCalendarJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(FixtureCalendarImportService $import): void
    {
        PipelineRun::track('ImportFixtureCalendarJob', fn () => $import->run());
    }
}
