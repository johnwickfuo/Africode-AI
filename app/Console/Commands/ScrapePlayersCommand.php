<?php

namespace App\Console\Commands;

use App\Jobs\ScrapePlayerStatsJob;
use Illuminate\Console\Command;

class ScrapePlayersCommand extends Command
{
    protected $signature = 'africode:scrape-players
        {--now : Run in this process instead of queueing}
        {--batch= : Override the batch size for this run (manual catch-up)}';

    protected $description = 'Scrape one batch of FBref player match stats (resumable backfill + nightly increments)';

    public function handle(): int
    {
        $batch = $this->option('batch') !== null ? (int) $this->option('batch') : null;

        if ($this->option('now')) {
            $this->info('Scraping one player-stats batch (one FBref request per match, politely throttled)...');
            ScrapePlayerStatsJob::dispatchSync($batch);
            $this->info('Batch finished. Re-run to continue the backfill; see player_scrape_progress for status.');
        } else {
            ScrapePlayerStatsJob::dispatch($batch);
            $this->info('ScrapePlayerStatsJob queued.');
        }

        return self::SUCCESS;
    }
}
