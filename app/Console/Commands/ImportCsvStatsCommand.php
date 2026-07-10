<?php

namespace App\Console\Commands;

use App\Jobs\ImportCsvStatsJob;
use Illuminate\Console\Command;

class ImportCsvStatsCommand extends Command
{
    protected $signature = 'africode:import-csv-stats
        {--now : Run in this process instead of queueing}';

    protected $description = 'Download and import match stats (corners, cards, shots, referees) from football-data.co.uk CSVs';

    public function handle(): int
    {
        if ($this->option('now')) {
            $this->info('Downloading 15 CSV files (3 seasons x 5 leagues) and importing...');
            ImportCsvStatsJob::dispatchSync();
            $this->info('CSV stats imported. Now run: php artisan africode:recompute-profiles --now');
        } else {
            ImportCsvStatsJob::dispatch();
            $this->info('ImportCsvStatsJob queued.');
        }

        return self::SUCCESS;
    }
}
