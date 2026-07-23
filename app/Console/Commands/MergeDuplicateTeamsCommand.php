<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\League;
use App\Models\MatchStat;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\Team;
use App\Models\TeamProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot repair for the duplicate clubs created when the FBref importer's
 * strict name matching met teams other sources had created under shorter
 * spellings ("Ipswich" vs "Ipswich Town"). Each duplicate dragged a full
 * set of phantom fixtures with it, double-counting matches in the profiles.
 *
 * The pair list is explicit — fuzzy auto-detection would swallow genuinely
 * distinct clubs like Paris FC / Paris Saint-Germain. Pairs where either
 * side no longer exists are skipped, so re-running is harmless.
 */
class MergeDuplicateTeamsCommand extends Command
{
    protected $signature = 'africode:merge-duplicate-teams {--dry-run : Report what would change without saving}';

    protected $description = 'Merge known duplicate teams (and their phantom fixtures) into the canonical clubs';

    /** league code => [duplicate name => canonical name] */
    private const PAIRS = [
        'PL' => [
            'Ipswich' => 'Ipswich Town',
            'Leicester' => 'Leicester City',
            'Luton' => 'Luton Town',
            'Newcastle' => 'Newcastle United',
            'Nottingham' => 'Nottingham Forest',
        ],
        'BL1' => [
            'Darmstadt' => 'Darmstadt 98',
            'Frankfurt' => 'Eintracht Frankfurt',
        ],
        'FL1' => [
            'Clermont' => 'Clermont Foot',
            'St Etienne' => 'Saint-Étienne',
        ],
    ];

    public function handle(): int
    {
        DB::beginTransaction();

        try {
            foreach (self::PAIRS as $leagueCode => $pairs) {
                $league = League::where('code', $leagueCode)->first();
                if ($league === null) {
                    continue;
                }

                foreach ($pairs as $dupeName => $canonicalName) {
                    $dupe = Team::where('league_id', $league->id)->where('name', $dupeName)->first();
                    $canonical = Team::where('league_id', $league->id)->where('name', $canonicalName)->first();

                    if ($dupe === null || $canonical === null || $dupe->id === $canonical->id) {
                        $this->line("{$leagueCode}: '{$dupeName}' → '{$canonicalName}' — nothing to merge, skipped.");

                        continue;
                    }

                    $this->merge($dupe, $canonical);
                }
            }
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        if ($this->option('dry-run')) {
            DB::rollBack();
            $this->warn('Dry run — every change rolled back.');
        } else {
            DB::commit();
            $this->info('Merge finished. Now run: php artisan africode:recompute-profiles --now');
        }

        return self::SUCCESS;
    }

    private function merge(Team $dupe, Team $canonical): void
    {
        $summary = ['fixtures_merged' => 0, 'fixtures_remapped' => 0, 'stat_rows_merged' => 0, 'player_rows_moved' => 0];

        $fixtures = Fixture::where('home_team_id', $dupe->id)->orWhere('away_team_id', $dupe->id)->get();

        foreach ($fixtures as $fixture) {
            $mappedHome = $fixture->home_team_id === $dupe->id ? $canonical->id : $fixture->home_team_id;
            $mappedAway = $fixture->away_team_id === $dupe->id ? $canonical->id : $fixture->away_team_id;

            $target = Fixture::where([
                'league_id' => $fixture->league_id,
                'season' => $fixture->season,
                'home_team_id' => $mappedHome,
                'away_team_id' => $mappedAway,
            ])->where('id', '!=', $fixture->id)->first();

            if ($target === null) {
                // No canonical twin — the fixture itself is fine, only the
                // team reference is wrong.
                $fixture->update(['home_team_id' => $mappedHome, 'away_team_id' => $mappedAway]);
                $summary['fixtures_remapped']++;

                continue;
            }

            // The phantom duplicates a real fixture: keep the real one,
            // salvage anything the phantom knows that the real one doesn't.
            foreach (['referee_id', 'matchday', 'fbref_game_id'] as $field) {
                $target->{$field} ??= $fixture->{$field};
            }
            $target->save();

            foreach (MatchStat::where('fixture_id', $fixture->id)->get() as $row) {
                $teamId = $row->team_id === $dupe->id ? $canonical->id : $row->team_id;
                $existing = MatchStat::where('fixture_id', $target->id)->where('team_id', $teamId)->first();

                if ($existing === null) {
                    $row->update(['fixture_id' => $target->id, 'team_id' => $teamId]);
                } else {
                    foreach ($existing->getFillable() as $field) {
                        if (! in_array($field, ['fixture_id', 'team_id', 'source'], true)) {
                            $existing->{$field} ??= $row->{$field};
                        }
                    }
                    $existing->save();
                    $row->delete();
                    $summary['stat_rows_merged']++;
                }
            }

            foreach (PlayerMatchStat::where('fixture_id', $fixture->id)->get() as $row) {
                $teamId = $row->team_id === $dupe->id ? $canonical->id : $row->team_id;
                $existing = PlayerMatchStat::where('fixture_id', $target->id)->where('player_id', $row->player_id)->first();

                if ($existing === null) {
                    $row->update(['fixture_id' => $target->id, 'team_id' => $teamId]);
                    $summary['player_rows_moved']++;
                } else {
                    foreach ($existing->getFillable() as $field) {
                        if (! in_array($field, ['fixture_id', 'player_id', 'team_id'], true)) {
                            $existing->{$field} ??= $row->{$field};
                        }
                    }
                    $existing->save();
                    $row->delete();
                }
            }

            $fixture->delete();
            $summary['fixtures_merged']++;
        }

        // Anything still pointing at the duplicate follows it to the canonical club.
        MatchStat::where('team_id', $dupe->id)->update(['team_id' => $canonical->id]);
        PlayerMatchStat::where('team_id', $dupe->id)->update(['team_id' => $canonical->id]);
        Player::where('team_id', $dupe->id)->update(['team_id' => $canonical->id]);
        TeamProfile::where('team_id', $dupe->id)->delete(); // recomputed after the merge

        $dupe->delete();

        $this->info(sprintf(
            '%s: merged "%s" into "%s" — %d duplicate fixtures merged, %d remapped, %d stat rows folded, %d player rows moved.',
            $canonical->league->code, $dupe->name, $canonical->name,
            $summary['fixtures_merged'], $summary['fixtures_remapped'],
            $summary['stat_rows_merged'], $summary['player_rows_moved'],
        ));
    }
}
