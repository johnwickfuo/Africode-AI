<?php

namespace App\Console\Commands;

use App\Jobs\RecomputeProfilesJob;
use Illuminate\Console\Command;

class RecomputeProfilesCommand extends Command
{
    protected $signature = 'africode:recompute-profiles
        {--now : Run the recomputation in this process instead of queueing it}';

    protected $description = 'Rebuild team and referee rolling stat profiles from match_stats';

    public function handle(): int
    {
        if ($this->option('now')) {
            RecomputeProfilesJob::dispatchSync();
            $this->info('Profiles recomputed.');
        } else {
            RecomputeProfilesJob::dispatch();
            $this->info('RecomputeProfilesJob queued.');
        }

        return self::SUCCESS;
    }
}
