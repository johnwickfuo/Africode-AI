<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes fixtures whose kickoff does not belong to the season they are
 * labelled with. Written for a real incident: requesting a season
 * football-data.co.uk had not published yet returned a file from a
 * century earlier, so 1926-27 First Division results were imported as
 * "2026-2027" — which would have poisoned this season's team profiles.
 *
 * The rule is general (a 2026-2027 fixture must kick off in 2026 or
 * 2027), so it also catches any future mislabelling. Deleting a fixture
 * cascades to its match stats, predictions and odds.
 */
class PurgeMislabelledFixturesCommand extends Command
{
    protected $signature = 'africode:purge-mislabelled-fixtures
        {--dry-run : Report what would be deleted without saving}
        {--prune-teams : Also delete teams left with no fixtures at all}';

    protected $description = 'Delete fixtures whose kickoff year does not match their season label';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        try {
            $doomed = Fixture::query()
                ->with(['league:id,code'])
                ->get(['id', 'league_id', 'season', 'kickoff_utc'])
                ->filter(function (Fixture $fixture) {
                    if ($fixture->kickoff_utc === null || ! str_contains((string) $fixture->season, '-')) {
                        return false;
                    }
                    [$start, $end] = array_map('intval', explode('-', $fixture->season));

                    return (int) $fixture->kickoff_utc->year !== $start
                        && (int) $fixture->kickoff_utc->year !== $end;
                });

            if ($doomed->isEmpty()) {
                $this->info('No mislabelled fixtures found.');
                DB::rollBack();

                return self::SUCCESS;
            }

            foreach ($doomed->groupBy(fn (Fixture $f) => $f->league->code.' '.$f->season) as $group => $rows) {
                $this->line(sprintf(
                    '%s: %d fixtures, kickoffs %s to %s',
                    $group,
                    $rows->count(),
                    $rows->min('kickoff_utc')->toDateString(),
                    $rows->max('kickoff_utc')->toDateString(),
                ));
            }

            Fixture::whereIn('id', $doomed->pluck('id'))->delete();
            $this->info($doomed->count().' fixtures deleted (match stats, odds and predictions cascade).');

            $orphans = Team::query()
                ->whereDoesntHave('homeFixtures')
                ->whereDoesntHave('awayFixtures')
                ->get(['id', 'name']);

            if ($orphans->isNotEmpty()) {
                $this->line('Teams left with no fixtures: '.$orphans->pluck('name')->implode(', '));

                if ($this->option('prune-teams')) {
                    Team::whereIn('id', $orphans->pluck('id'))->delete();
                    $this->info($orphans->count().' orphan teams deleted.');
                } else {
                    $this->comment('Re-run with --prune-teams to delete them.');
                }
            }
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        if ($dryRun) {
            DB::rollBack();
            $this->warn('Dry run — every change rolled back.');
        } else {
            DB::commit();
            $this->info('Done. Now run: php artisan africode:recompute-profiles --now');
        }

        return self::SUCCESS;
    }
}
