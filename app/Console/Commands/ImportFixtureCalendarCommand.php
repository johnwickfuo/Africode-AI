<?php

namespace App\Console\Commands;

use App\Jobs\ImportFixtureCalendarJob;
use Illuminate\Console\Command;

class ImportFixtureCalendarCommand extends Command
{
    protected $signature = 'africode:import-fixture-calendar
        {--now : Run synchronously instead of queueing}';

    protected $description = 'Import full season fixture calendars for leagues outside the football-data.org free tier';

    public function handle(): int
    {
        if ($this->option('now')) {
            ImportFixtureCalendarJob::dispatchSync();
            $this->info('Fixture calendar import finished.');
        } else {
            ImportFixtureCalendarJob::dispatch();
            $this->info('ImportFixtureCalendarJob queued.');
        }

        return self::SUCCESS;
    }
}
