<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePredictionsJob;
use App\Jobs\ImportFbrefDataJob;
use App\Jobs\RecomputeProfilesJob;
use App\Jobs\ScrapeFbrefJob;
use Illuminate\Console\Command;

class SeedHistoryCommand extends Command
{
    protected $signature = 'africode:seed-history';

    protected $description = 'One-time historical seed: scrape 3 FBref seasons, import them, build profiles, and generate predictions';

    public function handle(): int
    {
        $this->info('Step 1/4 — scraping FBref (3 seasons of Big-5 match logs).');
        $this->warn('The first run takes a long while by design: soccerdata scrapes politely and builds its cache. Later nightly runs are incremental.');
        ScrapeFbrefJob::dispatchSync();

        $this->info('Step 2/4 — importing match stats, results, and referees.');
        ImportFbrefDataJob::dispatchSync();

        $this->info('Step 3/4 — recomputing team and referee profiles.');
        RecomputeProfilesJob::dispatchSync();

        $this->info('Step 4/4 — generating predictions for upcoming fixtures.');
        GeneratePredictionsJob::dispatchSync();

        $this->info('Historical seed complete. Sync fixtures with: php artisan africode:sync-fixtures --now');

        return self::SUCCESS;
    }
}
