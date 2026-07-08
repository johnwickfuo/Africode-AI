<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\Profiles\RecomputeProfilesService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nightly (03:15 Africa/Lagos, after the FBref import and fixture sync):
 * rebuilds team_profiles and referee profile columns from match_stats.
 * Pure local computation — deterministic, no external calls.
 */
class RecomputeProfilesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function handle(RecomputeProfilesService $profiles): void
    {
        PipelineRun::track('RecomputeProfilesJob', fn () => $profiles->run());
    }
}
