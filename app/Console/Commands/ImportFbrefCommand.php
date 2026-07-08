<?php

namespace App\Console\Commands;

use App\Jobs\ImportFbrefDataJob;
use Illuminate\Console\Command;

class ImportFbrefCommand extends Command
{
    protected $signature = 'africode:import-fbref
        {--now : Run the import in this process instead of queueing it}';

    protected $description = 'Import the scraped FBref JSON into match_stats, fixtures, and referees';

    public function handle(): int
    {
        if ($this->option('now')) {
            ImportFbrefDataJob::dispatchSync();
            $this->info('FBref import finished.');
        } else {
            ImportFbrefDataJob::dispatch();
            $this->info('ImportFbrefDataJob queued.');
        }

        return self::SUCCESS;
    }
}
