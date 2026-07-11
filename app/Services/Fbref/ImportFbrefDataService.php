<?php

namespace App\Services\Fbref;

use App\Models\Fixture;
use App\Models\League;
use App\Models\MatchStat;
use App\Models\Referee;
use App\Models\Rivalry;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports the JSON written by scripts/fbref_scrape.py: upserts match_stats
 * (one row per team per finished match), fixtures (historical results that
 * football-data.org never covered, plus result confirmation for current-season
 * rows), and referees.
 *
 * Historical seasons contain relegated/promoted clubs that the current-season
 * seeder doesn't know (Luton, Schalke, ...). Those teams are auto-created —
 * the models need their matches for opponent strengths and league averages.
 */
class ImportFbrefDataService
{
    /** soccerdata Big-5 league keys => our league codes. */
    public const LEAGUE_MAP = [
        'ENG-Premier League' => 'PL',
        'ESP-La Liga' => 'PD',
        'ITA-Serie A' => 'SA',
        'GER-Bundesliga' => 'BL1',
        'FRA-Ligue 1' => 'FL1',
    ];

    private const STAT_FIELDS = [
        'goals', 'xg', 'xga', 'shots', 'shots_on_target', 'shots_on_target_against',
        'corners_for', 'corners_against', 'crosses', 'fouls_committed', 'fouls_drawn',
        'yellows', 'reds', 'possession',
    ];

    /** @var array<int, \Illuminate\Support\Collection<string, Team>> league id => teams keyed by fbref_name */
    private array $teamCache = [];

    /**
     * @return array{fixtures_created: int, fixtures_updated: int, stats_rows: int, matches_skipped: int, teams_created: int}
     */
    public function run(?string $path = null): array
    {
        $path ??= config('africode.fbref.output_path');

        if (! is_file($path)) {
            throw new RuntimeException(
                "FBref data file not found at {$path}. Run the scrape first: php artisan africode:scrape-fbref"
            );
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $matches = $payload['matches'] ?? null;

        if (! is_array($matches)) {
            throw new RuntimeException("FBref data file {$path} has no 'matches' array.");
        }

        $summary = [
            'fixtures_created' => 0,
            'fixtures_updated' => 0,
            'stats_rows' => 0,
            'matches_skipped' => 0,
            'teams_created' => 0,
        ];

        $leagues = League::all()->keyBy('code');
        $this->teamCache = [];

        foreach ($matches as $match) {
            $leagueCode = self::LEAGUE_MAP[$match['league'] ?? ''] ?? null;
            $league = $leagueCode !== null ? $leagues->get($leagueCode) : null;

            if ($league === null) {
                $summary['matches_skipped']++;
                Log::warning('FBref import: unknown league, match skipped', [
                    'league' => $match['league'] ?? null,
                    'game' => $match['game'] ?? null,
                ]);

                continue;
            }

            if (blank($match['home_team'] ?? null) || blank($match['away_team'] ?? null)) {
                $summary['matches_skipped']++;

                continue;
            }

            $home = $this->resolveTeam($league, $match['home_team'], $summary);
            $away = $this->resolveTeam($league, $match['away_team'], $summary);

            $fixture = $this->upsertFixture($league, $match, $home, $away);

            if ($fixture->wasRecentlyCreated) {
                $summary['fixtures_created']++;
            } elseif ($fixture->wasChanged()) {
                $summary['fixtures_updated']++;
            }

            $summary['stats_rows'] += $this->upsertMatchStats($fixture, $home, $match['home'] ?? [], true);
            $summary['stats_rows'] += $this->upsertMatchStats($fixture, $away, $match['away'] ?? [], false);
        }

        Log::info('FBref import finished', $summary + ['generated_at' => $payload['generated_at'] ?? null]);

        return $summary;
    }

    private function resolveTeam(League $league, string $fbrefName, array &$summary): Team
    {
        $teams = $this->teamCache[$league->id] ??= $league->teams()->get()->keyBy('fbref_name');

        $team = $teams->get($fbrefName) ?? $this->fuzzyMatch($teams, $fbrefName);

        if ($team === null) {
            $team = Team::create([
                'league_id' => $league->id,
                'name' => $fbrefName,
                'fbref_name' => $fbrefName,
                'short_name' => Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', Str::ascii($fbrefName)), 0, 3)),
            ]);
            $this->teamCache[$league->id]->put($fbrefName, $team);
            $summary['teams_created']++;
            Log::info('FBref import: created team not in seed (historical season)', [
                'league' => $league->code,
                'team' => $fbrefName,
            ]);
        }

        return $team;
    }

    /**
     * A team created by the fixture sync (promoted side) carries a guessed
     * fbref_name. When FBref's real squad name arrives, match it against
     * the club's known names and adopt it — instead of creating a duplicate.
     */
    private function fuzzyMatch($teams, string $fbrefName): ?Team
    {
        $normalize = fn (string $value) => preg_replace('/[^a-z0-9]/', '', Str::ascii(Str::lower($value)));
        $needle = $normalize($fbrefName);

        foreach ($teams as $team) {
            $candidates = [$team->name, $team->fbref_name, $team->short_name];
            if (in_array($needle, array_map($normalize, $candidates), true)) {
                // Adopt FBref's real squad name as the join key.
                $this->teamCache[$team->league_id]->forget($team->fbref_name);
                $team->update(['fbref_name' => $fbrefName]);
                $this->teamCache[$team->league_id]->put($fbrefName, $team);

                return $team;
            }
        }

        return null;
    }

    private function upsertFixture(League $league, array $match, Team $home, Team $away): Fixture
    {
        // A league pairing occurs once per venue per season, so this composite
        // finds the row created by the football-data.org sync when one exists.
        $fixture = Fixture::firstOrNew([
            'league_id' => $league->id,
            'season' => $this->seasonLabel($match['season']),
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);

        if (! $fixture->exists) {
            // FBref kickoff is venue-local; stored as-is (~UTC). Only used for
            // historical rows — football-data.org owns current-season times.
            $fixture->kickoff_utc = $this->parseKickoff($match);
        }

        $fixture->status = Fixture::STATUS_FINISHED;
        $fixture->home_goals = $match['home_goals'];
        $fixture->away_goals = $match['away_goals'];
        $fixture->matchday ??= $match['matchday'] ?? null;
        $fixture->fbref_game_id ??= $match['game_id'] ?? null;
        $fixture->is_derby = Rivalry::isDerbyPair($home->id, $away->id);

        if (filled($match['referee'] ?? null)) {
            $fixture->referee_id ??= Referee::firstOrCreate(['name' => $match['referee']])->id;
        }

        $fixture->save();

        return $fixture;
    }

    /**
     * Real FBref exports glue pandas' midnight-stamped date to a venue-time
     * kickoff — "2023-08-12 00:00:00 12:30 (13:30)" — which no date parser
     * accepts. Strip the parenthetical, and when plain parsing still fails,
     * recompose from the date plus the last time present (the actual kickoff).
     */
    private function parseKickoff(array $match): Carbon
    {
        $raw = trim(preg_replace('/\([^)]*\)/', '', (string) ($match['kickoff'] ?? $match['date'] ?? '')));

        try {
            return Carbon::parse($raw, 'UTC');
        } catch (\Throwable) {
            if (preg_match('/\d{4}-\d{2}-\d{2}/', $raw, $date) !== 1) {
                throw new RuntimeException("Unparseable FBref kickoff '{$raw}'.");
            }
            preg_match_all('/\d{1,2}:\d{2}(?::\d{2})?/', $raw, $times);

            return Carbon::parse($date[0].' '.(end($times[0]) ?: '00:00'), 'UTC');
        }
    }

    private function upsertMatchStats(Fixture $fixture, Team $team, array $stats, bool $isHome): int
    {
        $attributes = ['is_home' => $isHome, 'source' => MatchStat::SOURCE_FBREF];
        foreach (self::STAT_FIELDS as $field) {
            $attributes[$field] = $stats[$field] ?? null;
        }

        MatchStat::updateOrCreate(
            ['fixture_id' => $fixture->id, 'team_id' => $team->id],
            $attributes,
        );

        return 1;
    }

    /**
     * soccerdata season key "2526" => "2025-2026" (already-long labels pass through).
     */
    private function seasonLabel(string $season): string
    {
        if (preg_match('/^\d{4}$/', $season) === 1) {
            return '20'.substr($season, 0, 2).'-20'.substr($season, 2, 2);
        }

        return $season;
    }
}
