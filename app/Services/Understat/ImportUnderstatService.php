<?php

namespace App\Services\Understat;

use App\Models\Fixture;
use App\Models\League;
use App\Models\MatchStat;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports the JSON written by scripts/understat_scrape.py:
 *
 *  - player_match_stats: minutes, goals, assists, shots, xG, xA, cards
 *    per player per match (shots_on_target stays null — Understat doesn't
 *    track it; an FBref row, when available, fills it and is never
 *    degraded by this importer).
 *  - match_stats xG enrichment: per-match team xG/xGA onto rows imported
 *    from the football-data.co.uk CSVs, restoring the goals model's
 *    70/30 xG blend without FBref. FBref-sourced rows are untouched.
 *
 * Fixtures are matched (league, season, home, away) against rows created
 * by the CSV/FBref/fixture-sync imports; teams resolve by normalized-token
 * matching with a small alias map. Unknown teams skip the match — the CSV
 * import runs first and creates every club, so unknowns mean alias gaps,
 * not missing data.
 */
class ImportUnderstatService
{
    /** Understat team names no generic rule can bridge. */
    private const NAME_ALIASES = [
        'Borussia M.Gladbach' => 'Borussia Mönchengladbach',
        'FC Cologne' => '1. FC Köln',
        'Paris Saint Germain' => 'Paris Saint-Germain',
        'RasenBallsport Leipzig' => 'RB Leipzig',
    ];

    private const STOP_TOKENS = [
        'fc', 'afc', 'cf', 'ac', 'as', 'ss', 'us', 'rc', 'sc', 'sv', 'st',
        'club', 'de', 'real', 'deportivo', 'stade', 'olympique',
    ];

    /** @var array<int, Collection<int, Team>> */
    private array $teamsByLeague = [];

    /**
     * @return array{matches_imported: int, matches_skipped: int, player_rows: int, players_created: int, xg_enriched: int}
     */
    public function run(?string $path = null): array
    {
        $path ??= config('africode.understat.output_path');

        if (! is_file($path)) {
            throw new RuntimeException("Understat data file not found at {$path}.");
        }

        // The full-history file is ~26 MB of JSON that decodes to several
        // hundred MB of PHP arrays — beyond typical 256 MB CLI limits.
        if ((int) ini_get('memory_limit') !== -1) {
            ini_set('memory_limit', '1024M');
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $summary = [
            'matches_imported' => 0, 'matches_skipped' => 0,
            'player_rows' => 0, 'players_created' => 0, 'xg_enriched' => 0,
        ];

        $leagues = League::all()->keyBy('code');

        foreach ($payload['matches'] ?? [] as $match) {
            $league = $leagues->get($match['league'] ?? '');
            if ($league === null) {
                $summary['matches_skipped']++;

                continue;
            }

            $home = $this->resolveTeam($league, $match['home_team'] ?? '');
            $away = $this->resolveTeam($league, $match['away_team'] ?? '');
            $fixture = ($home && $away) ? Fixture::where([
                'league_id' => $league->id,
                'season' => $this->seasonLabel($match['season'] ?? ''),
                'home_team_id' => $home->id,
                'away_team_id' => $away->id,
            ])->first() : null;

            if ($fixture === null) {
                $summary['matches_skipped']++;
                Log::info('Understat import: no matching fixture', [
                    'league' => $match['league'] ?? null,
                    'home' => $match['home_team'] ?? null,
                    'away' => $match['away_team'] ?? null,
                ]);

                continue;
            }

            $summary['xg_enriched'] += $this->enrichTeamXg($fixture, $match);

            foreach ($match['players'] ?? [] as $row) {
                $this->importPlayerRow($fixture, $row['side'] === 'home' ? $home : $away, $row, $summary);
            }

            $summary['matches_imported']++;
        }

        Log::info('Understat import finished', $summary);

        return $summary;
    }

    /**
     * Put Understat's per-match team xG onto CSV-sourced match_stats rows.
     * FBref rows keep their own (usually near-identical) xG.
     */
    private function enrichTeamXg(Fixture $fixture, array $match): int
    {
        if ($match['home_xg'] === null || $match['away_xg'] === null) {
            return 0;
        }

        $enriched = 0;
        $stats = MatchStat::where('fixture_id', $fixture->id)->get();

        foreach ($stats as $stat) {
            // Never degrade an FBref row that has its own xG — but an FBref
            // row whose xG came back empty is a gap Understat should fill.
            if ($stat->source === MatchStat::SOURCE_FBREF && $stat->xg !== null) {
                continue;
            }
            $isHome = $stat->team_id === $fixture->home_team_id;
            $stat->xg = $isHome ? $match['home_xg'] : $match['away_xg'];
            $stat->xga = $isHome ? $match['away_xg'] : $match['home_xg'];
            if ($stat->isDirty()) {
                $stat->save();
                $enriched++;
            }
        }

        return $enriched;
    }

    private function importPlayerRow(Fixture $fixture, Team $team, array $row, array &$summary): void
    {
        if (blank($row['name'] ?? null)) {
            return;
        }

        $player = Player::where('name', $row['name'])->first();

        if ($player === null) {
            $player = Player::create([
                'team_id' => $team->id,
                'name' => $row['name'],
                'position' => $row['position'] ?? null,
                'nationality' => null, // Understat doesn't publish it
                'last_seen_at' => $fixture->kickoff_utc,
            ]);
            $summary['players_created']++;
        } elseif ($player->last_seen_at === null || $fixture->kickoff_utc->gte($player->last_seen_at)) {
            // Same transfer guard as the FBref importer: only a newer match
            // may move the player's current team.
            $player->update([
                'team_id' => $team->id,
                'position' => $row['position'] ?? $player->position,
                'last_seen_at' => $fixture->kickoff_utc,
            ]);
        }

        $stat = PlayerMatchStat::firstOrNew([
            'player_id' => $player->id,
            'fixture_id' => $fixture->id,
        ]);
        // Understat lacks shots-on-target; never null out an FBref value.
        $keepSot = $stat->shots_on_target;
        $stat->fill([
            'team_id' => $team->id,
            'minutes' => $row['minutes'] ?? null,
            'goals' => $row['goals'] ?? null,
            'assists' => $row['assists'] ?? null,
            'shots' => $row['shots'] ?? null,
            'shots_on_target' => $keepSot,
            'yellows' => $row['yellows'] ?? null,
            'reds' => $row['reds'] ?? null,
            'xg' => $row['xg'] ?? null,
            'xa' => $row['xa'] ?? null,
        ])->save();

        $summary['player_rows']++;
    }

    private function resolveTeam(League $league, string $understatName): ?Team
    {
        if ($understatName === '') {
            return null;
        }

        $teams = $this->teamsByLeague[$league->id] ??= $league->teams()->get();

        $alias = self::NAME_ALIASES[$understatName] ?? null;
        if ($alias !== null && ($team = $teams->firstWhere('name', $alias))) {
            return $team;
        }

        $needle = $this->tokens($understatName);
        $exact = $teams->first(fn (Team $team) => $this->tokens($team->name) === $needle
            || $this->tokens($team->fbref_name) === $needle);
        if ($exact) {
            return $exact;
        }

        $subset = $teams->filter(function (Team $team) use ($needle) {
            foreach ([$this->tokens($team->name), $this->tokens($team->fbref_name)] as $set) {
                if ($needle !== [] && $set !== []
                    && (array_diff($needle, $set) === [] || array_diff($set, $needle) === [])) {
                    return true;
                }
            }

            return false;
        });

        return $subset->count() === 1 ? $subset->first() : null;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $name): array
    {
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(Str::lower($name)));
        $tokens = array_values(array_filter(
            explode(' ', $ascii),
            fn (string $token) => $token !== ''
                && ! ctype_digit($token)
                && ! in_array($token, self::STOP_TOKENS, true),
        ));
        sort($tokens);

        return $tokens;
    }

    private function seasonLabel(string $seasonKey): string
    {
        return '20'.substr($seasonKey, 0, 2).'-20'.substr($seasonKey, 2, 2);
    }
}
