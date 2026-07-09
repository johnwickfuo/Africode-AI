<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAccumulatorsJob;
use Illuminate\Console\Command;

class GenerateAccumulatorsCommand extends Command
{
    protected $signature = 'africode:generate-accas
        {--now : Build in this process instead of queueing}';

    protected $description = 'Build the daily accumulator set (3x-10000x tiers) from the latest predictions';

    public function handle(): int
    {
        if ($this->option('now')) {
            GenerateAccumulatorsJob::dispatchSync();
            $this->info('Accumulators generated.');
        } else {
            GenerateAccumulatorsJob::dispatch();
            $this->info('GenerateAccumulatorsJob queued.');
        }

        return self::SUCCESS;
    }
}
