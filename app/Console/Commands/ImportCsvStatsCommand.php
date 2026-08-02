<?php

namespace App\Console\Commands;

use App\Jobs\ImportCsvStatsJob;
use App\Models\League;
use App\Support\Seasons;
use Illuminate\Console\Command;

class ImportCsvStatsCommand extends Command
{
    protected $signature = 'africode:import-csv-stats
        {--now : Run in this process instead of queueing}';

    protected $description = 'Download and import match stats (corners, cards, shots, referees) from football-data.co.uk CSVs';

    public function handle(): int
    {
        if ($this->option('now')) {
            $leagues = League::whereNotNull('fdcouk_code')->count();
            $seasons = count(config('africode.fbref.seasons') ?? Seasons::tracked());
            $this->info("Downloading {$leagues} division files x {$seasons} seasons and importing...");
            ImportCsvStatsJob::dispatchSync();
            $this->info('CSV stats imported. Now run: php artisan africode:recompute-profiles --now');
        } else {
            ImportCsvStatsJob::dispatch();
            $this->info('ImportCsvStatsJob queued.');
        }

        return self::SUCCESS;
    }
}
