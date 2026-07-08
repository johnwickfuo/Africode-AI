<?php

namespace App\Console\Commands;

use App\Jobs\SettlePredictionsJob;
use Illuminate\Console\Command;

class SettlePredictionsCommand extends Command
{
    protected $signature = 'africode:settle-predictions
        {--now : Settle in this process instead of queueing}';

    protected $description = 'Score pending prediction markets against finished fixtures and refresh model accuracy';

    public function handle(): int
    {
        if ($this->option('now')) {
            SettlePredictionsJob::dispatchSync();
            $this->info('Predictions settled.');
        } else {
            SettlePredictionsJob::dispatch();
            $this->info('SettlePredictionsJob queued.');
        }

        return self::SUCCESS;
    }
}
