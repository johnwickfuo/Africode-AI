<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAccumulatorsJob;
use App\Models\Accumulator;
use App\Models\Fixture;
use Illuminate\Console\Command;

class GenerateAccumulatorsCommand extends Command
{
    protected $signature = 'africode:generate-accas
        {--now : Build in this process instead of queueing}
        {--explain : Report what is blocking each tier instead of building}';

    protected $description = 'Build the daily accumulator set (3x-10000x tiers) from the latest predictions';

    public function handle(): int
    {
        if ($this->option('explain')) {
            return $this->explain();
        }

        if ($this->option('now')) {
            GenerateAccumulatorsJob::dispatchSync();
            $this->info('Accumulators generated.');
        } else {
            GenerateAccumulatorsJob::dispatch();
            $this->info('GenerateAccumulatorsJob queued.');
        }

        return self::SUCCESS;
    }

    /**
     * Why a tier has no fresh ticket. A definition is built only when it has
     * nothing outstanding, so one stuck ticket silently blocks its tier for
     * good — and a ticket that has lost legs to a deleted fixture cannot
     * retire on its own, because retirement is measured in legs that have
     * kicked off and the missing ones never will.
     */
    private function explain(): int
    {
        $upcoming = Fixture::upcoming()
            ->where('kickoff_utc', '<=', now('UTC')->addDays((int) config('africode.predict.days_ahead')))
            ->count();

        $withPredictions = Fixture::upcoming()
            ->where('kickoff_utc', '<=', now('UTC')->addDays((int) config('africode.predict.days_ahead')))
            ->whereHas('predictions', fn ($query) => $query->champion())
            ->count();

        $this->line("Upcoming fixtures in the prediction window: {$upcoming}");
        $this->line("...carrying a champion prediction: {$withPredictions}");
        $this->newLine();

        $pending = Accumulator::where('outcome', Accumulator::OUTCOME_PENDING)
            ->with('legs.fixture:id,kickoff_utc')
            ->orderBy('generated_at')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No outstanding tickets — every tier is free to build.');

            return self::SUCCESS;
        }

        $rows = $pending->map(function (Accumulator $acca) {
            $started = $acca->legs->filter(fn ($leg) => $leg->fixture?->kickoff_utc?->isPast())->count();
            $live = $acca->isLive();

            return [
                $acca->id,
                $acca->definitionKey(),
                $acca->generated_at?->diffForHumans(),
                $acca->legs->count().'/'.$acca->legs_count,
                $started,
                $live ? 'live (blocking)' : ($acca->isIntact() ? 'retired' : 'LEGS MISSING'),
            ];
        });

        $this->table(['id', 'definition', 'built', 'legs', 'started', 'state'], $rows);

        $broken = $pending->reject->isIntact();
        if ($broken->isNotEmpty()) {
            $this->warn(
                $broken->count().' ticket(s) have lost legs — their fixture or prediction was deleted. '
                .'They are excluded from the live set and the next settle run voids them.'
            );
        }

        return self::SUCCESS;
    }
}
