<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeUnderstatJob;
use Illuminate\Console\Command;

class ScrapeUnderstatCommand extends Command
{
    protected $signature = 'africode:scrape-understat
        {--now : Run in this process instead of queueing}
        {--batch= : New roster fetches this run (manual catch-up, e.g. 2000)}';

    protected $description = 'Scrape understat.com player match data + team xG (free, works from any IP)';

    public function handle(): int
    {
        $batch = $this->option('batch') !== null ? (int) $this->option('batch') : null;

        if ($this->option('now')) {
            $this->info('Scraping understat (~1.2s per uncached match roster, newest first)...');
            ScrapeUnderstatJob::dispatchSync($batch);
            $this->info('Understat scrape + import finished. Re-run to continue the backfill.');
        } else {
            ScrapeUnderstatJob::dispatch($batch);
            $this->info('ScrapeUnderstatJob queued.');
        }

        return self::SUCCESS;
    }
}
