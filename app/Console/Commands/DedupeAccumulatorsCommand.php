<?php

namespace App\Console\Commands;

use App\Models\Accumulator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off cleanup for tickets published more than once.
 *
 * Generation used to rebuild every definition each morning, so a ticket
 * targeting Saturday was republished daily until Saturday came — leaving
 * a stack of identical copies that all surfaced on the accuracy page and
 * counted separately in the record. The builder now leaves a live ticket
 * alone; this clears what the old behaviour left behind.
 */
class DedupeAccumulatorsCommand extends Command
{
    protected $signature = 'africode:dedupe-accas
        {--apply : Delete the duplicates instead of only reporting them}';

    protected $description = 'Collapse accumulators that were published more than once with identical legs';

    public function handle(): int
    {
        $all = Accumulator::with(['legs:id,accumulator_id,prediction_market_id,fixture_id', 'legs.fixture:id,kickoff_utc'])
            ->orderBy('generated_at')
            ->orderBy('id')
            ->get();

        // Same definition AND the same exact set of picks: keeping the
        // earliest loses nothing, because it is the one that was published
        // first and so the one anyone could have acted on.
        $identical = $all
            ->groupBy(fn (Accumulator $acca) => $acca->definitionKey().'|'.
                $acca->legs->pluck('prediction_market_id')->sort()->implode(','))
            ->filter(fn ($copies) => $copies->count() > 1);

        // A definition may only have one ticket standing at a time. Repeat
        // builds left near-copies that differ by a leg, which the check
        // above cannot see but which read as repeats all the same.
        $standing = $all
            ->filter(fn (Accumulator $acca) => $acca->isLive())
            ->groupBy(fn (Accumulator $acca) => 'live:'.$acca->definitionKey())
            ->filter(fn ($copies) => $copies->count() > 1);

        // Plain collections: merging Eloquent ones keys by model id.
        $groups = collect($identical->all())->merge($standing->all());

        if ($groups->isEmpty()) {
            $this->info('No duplicate accumulators found.');

            return self::SUCCESS;
        }

        $doomed = $groups->flatMap(fn ($copies) => $copies->skip(1)->pluck('id'))->unique()->values();

        $this->table(
            ['Ticket', 'Copies', 'Keeping'],
            $groups->map(fn ($copies) => [
                $copies->first()->definitionKey(),
                $copies->count(),
                $copies->first()->generated_at->toDateTimeString(),
            ])->values()->all(),
        );

        if (! $this->option('apply')) {
            $this->warn("{$doomed->count()} duplicate tickets would be deleted. Re-run with --apply.");

            return self::SUCCESS;
        }

        DB::transaction(fn () => Accumulator::whereIn('id', $doomed)->delete());

        $this->info("Deleted {$doomed->count()} duplicate tickets.");

        return self::SUCCESS;
    }
}
