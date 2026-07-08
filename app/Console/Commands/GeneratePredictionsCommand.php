<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePredictionsJob;
use Illuminate\Console\Command;

class GeneratePredictionsCommand extends Command
{
    protected $signature = 'africode:generate-predictions
        {--now : Generate in this process instead of queueing}';

    protected $description = 'Run the prediction models for all fixtures in the next 7 days';

    public function handle(): int
    {
        if ($this->option('now')) {
            GeneratePredictionsJob::dispatchSync();
            $this->info('Predictions generated.');
        } else {
            GeneratePredictionsJob::dispatch();
            $this->info('GeneratePredictionsJob queued.');
        }

        return self::SUCCESS;
    }
}
