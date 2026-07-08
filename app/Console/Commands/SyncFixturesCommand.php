<?php

namespace App\Console\Commands;

use App\Jobs\SyncFixturesJob;
use Illuminate\Console\Command;

class SyncFixturesCommand extends Command
{
    protected $signature = 'africode:sync-fixtures
        {--now : Run the sync in this process instead of queueing it}';

    protected $description = 'Sync fixtures & results from football-data.org (next 14 days + last 3 days, all leagues)';

    public function handle(): int
    {
        if ($this->option('now')) {
            $this->info('Running fixture sync synchronously (~40s: 7s throttle between the 5 league requests)...');
            SyncFixturesJob::dispatchSync();
            $this->info('Fixture sync finished.');
        } else {
            SyncFixturesJob::dispatch();
            $this->info('SyncFixturesJob queued. Ensure a queue worker is running: php artisan queue:work');
        }

        return self::SUCCESS;
    }
}
