<?php

namespace App\Console\Commands;

use App\Jobs\ImportOddsJob;
use Illuminate\Console\Command;

class ImportOddsCommand extends Command
{
    protected $signature = 'africode:import-odds
        {--now : Run synchronously instead of queueing}';

    protected $description = 'Import bookmaker odds for upcoming fixtures from football-data.co.uk';

    public function handle(): int
    {
        if ($this->option('now')) {
            ImportOddsJob::dispatchSync();
            $this->info('Odds import finished.');
        } else {
            ImportOddsJob::dispatch();
            $this->info('ImportOddsJob queued.');
        }

        return self::SUCCESS;
    }
}
