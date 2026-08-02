<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\Rivalry;
use Illuminate\Console\Command;

/**
 * Recomputes fixtures.is_derby for every fixture.
 *
 * Import jobs set the flag as they touch a fixture, so a rivalry-list change
 * — or a fix to the pair matching itself — only reaches matches inside the
 * tracked season window. Older seasons keep whatever they were given at
 * import time. This sweeps all of them.
 */
class RefreshDerbyFlagsCommand extends Command
{
    protected $signature = 'africode:refresh-derby-flags {--dry-run : Report what would change without saving}';

    protected $description = 'Recompute the derby flag on every fixture from the rivalry list';

    public function handle(): int
    {
        // Both orderings held in memory: one pass, no per-fixture queries.
        $pairs = Rivalry::get(['team1_id', 'team2_id'])
            ->flatMap(fn (Rivalry $rivalry) => [
                $rivalry->team1_id.'-'.$rivalry->team2_id => true,
                $rivalry->team2_id.'-'.$rivalry->team1_id => true,
            ]);

        $dryRun = (bool) $this->option('dry-run');
        $added = 0;
        $removed = 0;

        Fixture::query()->chunkById(1000, function ($fixtures) use ($pairs, $dryRun, &$added, &$removed) {
            foreach ($fixtures as $fixture) {
                $isDerby = $pairs->has($fixture->home_team_id.'-'.$fixture->away_team_id);

                if ((bool) $fixture->is_derby === $isDerby) {
                    continue;
                }

                $isDerby ? $added++ : $removed++;

                if (! $dryRun) {
                    $fixture->is_derby = $isDerby;
                    $fixture->save();
                }
            }
        });

        $this->info(sprintf(
            '%s%d fixtures flagged as derbies, %d unflagged. Derbies now: %d of %d.',
            $dryRun ? '[dry run] ' : '',
            $added,
            $removed,
            $dryRun
                ? Fixture::where('is_derby', true)->count() + $added - $removed
                : Fixture::where('is_derby', true)->count(),
            Fixture::count(),
        ));

        return self::SUCCESS;
    }
}
