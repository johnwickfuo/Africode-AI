<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeFbrefJob;
use Illuminate\Console\Command;

class ScrapeFbrefCommand extends Command
{
    protected $signature = 'africode:scrape-fbref
        {--now : Run the scrape in this process instead of queueing it}';

    protected $description = 'Scrape FBref team match logs (via soccerdata) into storage/app/pipeline/fbref_latest.json';

    public function handle(): int
    {
        if ($this->option('now')) {
            $this->info('Running FBref scrape synchronously. First run over 3 seasons takes hours (polite scraping) — later runs are incremental.');
            ScrapeFbrefJob::dispatchSync();
            $this->info('Scrape finished. Import with: php artisan africode:import-fbref --now');
        } else {
            ScrapeFbrefJob::dispatch();
            $this->info('ScrapeFbrefJob queued. Ensure the queue worker allows long jobs (see scrape_timeout_seconds).');
        }

        return self::SUCCESS;
    }
}
