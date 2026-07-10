<?php

namespace App\Services\FootballDataCoUk;

use App\Models\Fixture;
use App\Models\League;
use App\Models\MatchStat;
use App\Models\Referee;
use App\Models\Rivalry;
use App\Models\Team;
use App\Support\Seasons;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Imports match statistics from football-data.co.uk's free CSV downloads —
 * plain files, no API key, no Cloudflare, reachable from datacenter IPs.
 * One file per league per season carries results plus corners, cards,
 * fouls, shots, shots on target, and the referee.
 *
 * This is the stats source that needs nothing but an HTTP GET: it seeds
 * the 3-season history in seconds and keeps the current season fresh
 * nightly. FBref (when reachable, e.g. via FBREF_PROXY) remains the
 * richer source — its rows carry xG/crosses/possession, so an existing
 * fbref row is never overwritten by a CSV row.
 */
class CsvStatsImportService
{
    private const BASE_URL = 'https://www.football-data.co.uk/mmz4281';

    public const SOURCE = 'fdcouk';

    /** Our league codes => football-data.co.uk file names. */
    private const LEAGUE_FILES = [
        'PL' => 'E0',
        'PD' => 'SP1',
        'SA' => 'I1',
        'BL1' => 'D1',
        'FL1' => 'F1',
    ];

    /** football-data.co.uk team names that no generic rule can bridge. */
    private const NAME_ALIASES = [
        'Man United' => 'Manchester United',
        'Man City' => 'Manchester City',
        "Nott'm Forest" => 'Nottingham Forest',
        'Ath Madrid' => 'Atlético Madrid',
        'Ath Bilbao' => 'Athletic Club',
        'Espanol' => 'Espanyol',
        'Sociedad' => 'Real Sociedad',
        'Vallecano' => 'Rayo Vallecano',
        'Ein Frankfurt' => 'Eintracht Frankfurt',
        "M'gladbach" => 'Borussia Mönchengladbach',
        'Hamburg' => 'Hamburger SV',
        'Paris SG' => 'Paris Saint-Germain',
    ];

    private const STOP_TOKENS = [
        'fc', 'afc', 'cf', 'ac', 'as', 'ss', 'us', 'rc', 'sc', 'sv', 'st',
        'club', 'de', 'real', 'deportivo', 'stade', 'olympique',
    ];

    /** @var array<int, Collection<int, Team>> */
    private array $teamsByLeague = [];

    /**
     * @param  list<string>|null  $seasonKeys  e.g. ["2324","2425","2526"]; null = tracked window
     * @return array{fixtures_created: int, fixtures_updated: int, stats_rows: int, stats_skipped_fbref: int, teams_created: int, files_failed: int}
     */
    public function run(?array $seasonKeys = null): array
    {
        $seasonKeys ??= config('africode.fbref.seasons') ?? Seasons::tracked();

        $summary = [
            'fixtures_created' => 0, 'fixtures_updated' => 0, 'stats_rows' => 0,
            'stats_skipped_fbref' => 0, 'teams_created' => 0, 'files_failed' => 0,
        ];

        $leagues = League::all()->keyBy('code');
        $first = true;

        foreach ($seasonKeys as $seasonKey) {
            foreach (self::LEAGUE_FILES as $leagueCode => $file) {
                if (! $first) {
                    Sleep::for(2)->seconds(); // polite pacing between downloads
                }
                $first = false;

                $rows = $this->download($seasonKey, $file);
                if ($rows === null) {
                    $summary['files_failed']++;

                    continue;
                }

                $this->importFile($leagues[$leagueCode], $this->seasonLabel($seasonKey), $rows, $summary);
            }
        }

        Log::info('football-data.co.uk CSV import finished', $summary);

        return $summary;
    }

    /**
     * @return list<array<string, string>>|null rows as header-keyed maps
     */
    private function download(string $seasonKey, string $file): ?array
    {
        try {
            $response = Http::timeout(30)
                ->retry(3, 2000, throw: true)
                ->get(self::BASE_URL."/{$seasonKey}/{$file}.csv");

            $body = ltrim($response->throw()->body(), "\u{FEFF}"); // strip BOM
        } catch (\Throwable $exception) {
            Log::warning('football-data.co.uk download failed', [
                'season' => $seasonKey, 'file' => $file, 'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        if (count($lines) < 2) {
            return null;
        }

        $header = str_getcsv(array_shift($lines));

        return collect($lines)
            ->filter(fn (string $line) => trim($line) !== '')
            ->map(function (string $line) use ($header) {
                $values = str_getcsv($line);

                return collect($header)
                    ->mapWithKeys(fn ($column, $i) => [trim($column) => trim($values[$i] ?? '')])
                    ->all();
            })
            ->values()
            ->all();
    }

    private function importFile(League $league, string $season, array $rows, array &$summary): void
    {
        foreach ($rows as $row) {
            if (blank($row['HomeTeam'] ?? null) || blank($row['AwayTeam'] ?? null)
                || ! is_numeric($row['FTHG'] ?? null) || ! is_numeric($row['FTAG'] ?? null)) {
                continue; // unplayed or malformed line
            }

            $home = $this->resolveTeam($league, $row['HomeTeam'], $summary);
            $away = $this->resolveTeam($league, $row['AwayTeam'], $summary);

            $fixture = Fixture::firstOrNew([
                'league_id' => $league->id,
                'season' => $season,
                'home_team_id' => $home->id,
                'away_team_id' => $away->id,
            ]);

            if (! $fixture->exists) {
                $fixture->kickoff_utc = $this->kickoff($row);
            }
            $fixture->status = Fixture::STATUS_FINISHED;
            $fixture->home_goals = (int) $row['FTHG'];
            $fixture->away_goals = (int) $row['FTAG'];
            $fixture->is_derby = Rivalry::isDerbyPair($home->id, $away->id);
            if (filled($row['Referee'] ?? null)) {
                $fixture->referee_id ??= $this->resolveReferee($row['Referee'])->id;
            }
            $fixture->save();

            $fixture->wasRecentlyCreated
                ? $summary['fixtures_created']++
                : ($fixture->wasChanged() ? $summary['fixtures_updated']++ : null);

            $this->upsertStats($fixture, $home, true, $row, $summary);
            $this->upsertStats($fixture, $away, false, $row, $summary);
        }
    }

    private function upsertStats(Fixture $fixture, Team $team, bool $isHome, array $row, array &$summary): void
    {
        // Never overwrite an FBref row — it carries xG/crosses/possession
        // that these CSVs don't have.
        $existing = MatchStat::where('fixture_id', $fixture->id)->where('team_id', $team->id)->first();
        if ($existing !== null && $existing->source !== self::SOURCE) {
            $summary['stats_skipped_fbref']++;

            return;
        }

        $column = fn (string $homeCol, string $awayCol) => $isHome ? $row[$homeCol] ?? null : $row[$awayCol] ?? null;
        $value = fn (?string $raw) => is_numeric($raw) ? (int) $raw : null;

        MatchStat::updateOrCreate(
            ['fixture_id' => $fixture->id, 'team_id' => $team->id],
            [
                'is_home' => $isHome,
                'goals' => $value($column('FTHG', 'FTAG')),
                'shots' => $value($column('HS', 'AS')),
                'shots_on_target' => $value($column('HST', 'AST')),
                'shots_on_target_against' => $value($column('AST', 'HST')),
                'corners_for' => $value($column('HC', 'AC')),
                'corners_against' => $value($column('AC', 'HC')),
                'fouls_committed' => $value($column('HF', 'AF')),
                'fouls_drawn' => $value($column('AF', 'HF')),
                'yellows' => $value($column('HY', 'AY')),
                'reds' => $value($column('HR', 'AR')),
                'source' => self::SOURCE,
            ],
        );

        $summary['stats_rows']++;
    }

    private function resolveTeam(League $league, string $csvName, array &$summary): Team
    {
        $teams = $this->teamsByLeague[$league->id] ??= $league->teams()->get();

        $alias = self::NAME_ALIASES[$csvName] ?? null;
        if ($alias !== null && ($team = $teams->firstWhere('name', $alias))) {
            return $team;
        }

        $needle = $this->tokens($csvName);
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
        if ($subset->count() === 1) {
            return $subset->first();
        }

        // Relegated/historical club not in the seed — create it, mirroring
        // the FBref importer (whose fuzzy fallback will unify names later).
        $team = Team::create([
            'league_id' => $league->id,
            'name' => $csvName,
            'fbref_name' => $csvName,
            'short_name' => Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', Str::ascii($csvName)), 0, 3)),
        ]);
        $this->teamsByLeague[$league->id]->push($team);
        $summary['teams_created']++;
        Log::info('CSV import: created team not in seed', ['league' => $league->code, 'team' => $csvName]);

        return $team;
    }

    /**
     * football-data.co.uk abbreviates referees ("A Taylor"); match them to
     * a full-name referee from other sources before creating a new row.
     */
    private function resolveReferee(string $name): Referee
    {
        $name = trim($name);

        $existing = Referee::where('name', $name)->first();
        if ($existing) {
            return $existing;
        }

        if (preg_match('/^([A-Z])\.?\s+(.{3,})$/u', $name, $parts) === 1) {
            $match = Referee::where('name', 'like', $parts[1].'% '.$parts[2])->first();
            if ($match) {
                return $match;
            }
        }

        return Referee::create(['name' => $name]);
    }

    private function kickoff(array $row): Carbon
    {
        $date = $row['Date'] ?? '';
        $time = filled($row['Time'] ?? null) ? $row['Time'] : '15:00';
        $format = strlen($date) === 8 ? 'd/m/y H:i' : 'd/m/Y H:i';

        try {
            // UK kickoff times; close enough to UTC for date-level modeling.
            return Carbon::createFromFormat($format, "{$date} {$time}", 'UTC');
        } catch (\Throwable) {
            return Carbon::now('UTC');
        }
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
