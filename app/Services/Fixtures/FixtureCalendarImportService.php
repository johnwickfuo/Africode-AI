<?php

namespace App\Services\Fixtures;

use App\Models\Fixture;
use App\Models\League;
use App\Models\Rivalry;
use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Imports the FULL season calendar for leagues the football-data.org free
 * tier does not carry (League One, League Two, Scottish Premiership, Süper
 * Lig) from fixturedownload.com — a free, keyless CSV per league-season.
 *
 * Those leagues previously depended on football-data.co.uk's fixtures.csv,
 * which is a ~3-day rolling window: a division whose season had not kicked
 * off yet showed nothing at all, and one already running showed only the
 * next couple of days. With the published calendar they get the same
 * 14-day upcoming horizon as the API-covered leagues, from the day the
 * fixture list is released.
 *
 * The API-covered leagues are deliberately untouched: football-data.org
 * owns their kickoff times, matchdays and results.
 *
 * CSV shape:
 *   Match Number,Round Number,Date,Location,Home Team,Away Team,Result
 *   1,1,15/08/2026 21:00,Gaziantep Stadyumu,Gaziantep,Alanyaspor,
 */
class FixtureCalendarImportService
{
    private const BASE_URL = 'https://fixturedownload.com/download';

    /**
     * A pairing can recur inside one season (12-team leagues play each other
     * three or four times), so an existing row is only reused for a calendar
     * entry when the two kickoffs are in the same part of the season.
     */
    private const REUSE_WINDOW_DAYS = 21;

    /**
     * Tokens carrying no identity: legal-form and club-type words, plus the
     * articles that some sources keep and others drop ("Le Havre").
     */
    private const STOP_TOKENS = [
        'fc', 'afc', 'cf', 'cfc', 'acf', 'ac', 'as', 'aj', 'ss', 'ssc', 'us',
        'rc', 'rcd', 'ca', 'cd', 'ud', 'sc', 'sco', 'sv', 'fsv', 'vfl', 'vfb',
        'tsg', 'bc', 'ogc', 'losc', 'osc', 'st', 'club', 'calcio', 'balompie',
        'de', 'real', 'deportivo', 'stade', 'olympique', 'le', 'la', 'les',
    ];

    /**
     * fixturedownload spellings that no token rule can bridge to our seeded
     * name — abbreviations, nicknames, and one case ("RCD Espanyol de
     * Barcelona") where the generic subset rule finds two candidates.
     * Anything not listed here and not matched falls through to being
     * created, which is correct for a genuinely promoted club.
     */
    private const NAME_ALIASES = [
        'Man City' => 'Manchester City',
        'Man Utd' => 'Manchester United',
        'Spurs' => 'Tottenham Hotspur',
        "Nott'm Forest" => 'Nottingham Forest',
        'FC Bayern München' => 'Bayern Munich',
        'Internazionale' => 'Inter Milan',
        'RCD Espanyol de Barcelona' => 'Espanyol',
        'Havre Athletic Club' => 'Le Havre',
    ];

    /** @var array<string, Collection<int, Team>> keyed by country */
    private array $teamsByCountry = [];

    /**
     * @return array{leagues: int, rows_seen: int, fixtures_created: int, fixtures_updated: int, results_filled: int, teams_created: int, calendars_missing: int}
     */
    public function run(): array
    {
        $summary = [
            'leagues' => 0, 'rows_seen' => 0, 'fixtures_created' => 0,
            'fixtures_updated' => 0, 'results_filled' => 0,
            'teams_created' => 0, 'calendars_missing' => 0,
        ];

        foreach (League::whereNotNull('calendar_slug')->orderBy('id')->get() as $league) {
            $summary['leagues']++;

            foreach ($this->seasonStartYears() as $startYear) {
                $rows = $this->download($league, $startYear);

                if ($rows === null) {
                    $summary['calendars_missing']++;

                    continue;
                }

                $this->importSeason($league, $startYear, $rows, $summary);
            }
        }

        Log::info('Fixture calendar import finished', $summary);

        return $summary;
    }

    /**
     * The season in progress, plus the one starting within three months —
     * so a newly published calendar is picked up as soon as it appears
     * (the EFL releases its fixture list in late June).
     *
     * @return list<int>
     */
    private function seasonStartYears(): array
    {
        $now = now('UTC');

        return array_values(array_unique([
            $this->seasonStartYear($now),
            $this->seasonStartYear($now->copy()->addMonths(3)),
        ]));
    }

    private function seasonStartYear(Carbon $moment): int
    {
        return $moment->month >= 7 ? $moment->year : $moment->year - 1;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, int>  $summary
     */
    private function importSeason(League $league, int $startYear, array $rows, array &$summary): void
    {
        $season = $startYear.'-'.($startYear + 1);
        $timesConfirmed = $this->timesLookReal($rows);

        // Existing rows for this league-season, grouped by pairing, so a
        // fixture already created by the odds import (which has no matchday)
        // is updated in place rather than duplicated.
        $existing = Fixture::query()
            ->where('league_id', $league->id)
            ->where('season', $season)
            ->get()
            ->groupBy(fn (Fixture $fixture) => $fixture->home_team_id.'|'.$fixture->away_team_id);

        /** @var array<int, true> $claimed */
        $claimed = [];

        foreach ($rows as $row) {
            $kickoff = $this->kickoff($row['Date'] ?? '');
            $homeName = trim((string) ($row['Home Team'] ?? ''));
            $awayName = trim((string) ($row['Away Team'] ?? ''));

            if ($kickoff === null || $homeName === '' || $awayName === '') {
                continue;
            }
            $summary['rows_seen']++;

            $home = $this->resolveTeam($league, $homeName, $summary);
            $away = $this->resolveTeam($league, $awayName, $summary);
            if ($home->id === $away->id) {
                continue;
            }

            $round = is_numeric($row['Round Number'] ?? null) ? (int) $row['Round Number'] : null;
            $fixture = $this->matchExisting($existing, $claimed, $home, $away, $round, $kickoff);

            if ($fixture === null) {
                $fixture = new Fixture([
                    'league_id' => $league->id,
                    'season' => $season,
                    'home_team_id' => $home->id,
                    'away_team_id' => $away->id,
                    'status' => Fixture::STATUS_SCHEDULED,
                    'is_derby' => Rivalry::isDerbyPair($home->id, $away->id),
                ]);
            }

            // A postponed or finished fixture keeps its status: the calendar
            // is a schedule, not a results feed, and the stats import owns
            // what actually happened.
            // football-data.org is the better source where it has the match
            // at all — live kickoff changes, matchdays, referees — so a row
            // it already claimed is left exactly as it is. The calendar's
            // job there is only to fill the gap beyond the API's 14-day
            // window. Everywhere else it is the schedule of record, and it
            // says whether it knows the time or only the date.
            $apiOwned = $fixture->footballdata_match_id !== null;

            if (! $apiOwned && $fixture->status === Fixture::STATUS_SCHEDULED) {
                $fixture->kickoff_utc = $kickoff;
                $fixture->kickoff_confirmed = $timesConfirmed;
            }
            $fixture->matchday ??= $round;

            $created = ! $fixture->exists;
            $changed = $created || $fixture->isDirty();
            $fixture->save();

            $claimed[$fixture->id] = true;
            $existing[$home->id.'|'.$away->id] ??= collect();
            if ($created) {
                $existing[$home->id.'|'.$away->id]->push($fixture);
                $summary['fixtures_created']++;
            } elseif ($changed) {
                $summary['fixtures_updated']++;
            }

            // Results are the stats import's job, but filling a blank score
            // here keeps settlement moving when that file lags behind.
            if ($this->fillResult($fixture, $row['Result'] ?? '')) {
                $summary['results_filled']++;
            }
        }
    }

    /**
     * @param  Collection<string, Collection<int, Fixture>>  $existing
     * @param  array<int, true>  $claimed
     */
    private function matchExisting(
        Collection $existing,
        array $claimed,
        Team $home,
        Team $away,
        ?int $round,
        Carbon $kickoff,
    ): ?Fixture {
        $candidates = ($existing[$home->id.'|'.$away->id] ?? collect())
            ->reject(fn (Fixture $fixture) => isset($claimed[$fixture->id]));

        if ($round !== null && ($byRound = $candidates->firstWhere('matchday', $round))) {
            return $byRound;
        }

        // Otherwise the nearest kickoff, provided it is close enough to be
        // the same meeting rather than the reverse or a split-season repeat.
        return $candidates
            ->filter(fn (Fixture $fixture) => $fixture->matchday === null || $round === null)
            ->sortBy(fn (Fixture $fixture) => abs($fixture->kickoff_utc->diffInSeconds($kickoff)))
            ->first(fn (Fixture $fixture) => abs($fixture->kickoff_utc->diffInDays($kickoff)) <= self::REUSE_WINDOW_DAYS);
    }

    /**
     * A real fixture list spreads across several kick-off slots (Saturday
     * 15:00, Sunday lunchtime, midweek evenings). A whole season sharing one
     * time is a placeholder — the Süper Lig calendar is 21:00 for all 306
     * matches — so those dates are usable but their times are not.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function timesLookReal(array $rows): bool
    {
        $times = [];
        foreach ($rows as $row) {
            $parts = explode(' ', trim((string) ($row['Date'] ?? '')));
            if (count($parts) === 2) {
                $times[$parts[1]] = true;
            }
        }

        return count($times) > 1 || count($rows) < 20;
    }

    /** "2 - 1" once played, blank before. Never overwrites a stored score. */
    private function fillResult(Fixture $fixture, string $result): bool
    {
        if ($fixture->footballdata_match_id !== null
            || $fixture->home_goals !== null
            || $fixture->status !== Fixture::STATUS_SCHEDULED) {
            return false;
        }

        if (! preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $result, $score)) {
            return false;
        }

        $fixture->forceFill([
            'home_goals' => (int) $score[1],
            'away_goals' => (int) $score[2],
            'status' => Fixture::STATUS_FINISHED,
        ])->save();

        return true;
    }

    /** fixturedownload publishes "dd/MM/yyyy HH:mm", already in UTC. */
    private function kickoff(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y H:i', $value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string, string>>|null null when the season has no
     *                                          published calendar yet
     */
    private function download(League $league, int $startYear): ?array
    {
        $url = self::BASE_URL."/{$league->calendar_slug}-{$startYear}-UTC.csv";

        // Retry the network, not the answer: a 404 is the honest "this
        // season is not published yet" and must come back immediately.
        $response = Http::timeout(60)
            ->retry(3, 3000, fn ($exception) => $exception instanceof ConnectionException, throw: false)
            ->get($url);

        if ($response->failed()) {
            Log::info('Fixture calendar not published yet', [
                'league' => $league->code,
                'season' => $startYear,
                'status' => $response->status(),
            ]);

            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim(ltrim($response->body(), "\u{FEFF}")));
        if (count($lines) < 2) {
            return null;
        }

        $header = str_getcsv(array_shift($lines), ',', '"', '\\');

        return collect($lines)
            ->filter(fn (string $line) => trim($line) !== '')
            ->map(function (string $line) use ($header) {
                $values = str_getcsv($line, ',', '"', '\\');

                return collect($header)
                    ->mapWithKeys(fn ($column, $i) => [trim((string) $column) => trim($values[$i] ?? '')])
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * Candidates are every club in the same country: a side promoted or
     * relegated between two tracked divisions must keep its existing row,
     * and its history with it.
     *
     * @param  array<string, int>  $summary
     */
    private function resolveTeam(League $league, string $name, array &$summary): Team
    {
        $teams = $this->teamsByCountry[$league->country] ??= Team::query()
            ->whereHas('league', fn ($query) => $query->where('country', $league->country))
            ->get();

        $alias = self::NAME_ALIASES[$name] ?? null;
        if ($alias !== null && ($team = $teams->firstWhere('name', $alias))) {
            return $team;
        }

        $needle = $this->tokens($name);

        $exact = $teams->first(fn (Team $team) => $this->tokens($team->name) === $needle
            || $this->tokens($team->fbref_name) === $needle
            || $this->tokens($team->fdcouk_name) === $needle);
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

        // A club we have never seen — newly promoted into a tracked division.
        // Create it so its fixtures appear from matchday 1; the stats import
        // corrects the source-specific spellings on first match data.
        $team = Team::create([
            'league_id' => $league->id,
            'name' => $name,
            'fbref_name' => $name,
            'short_name' => Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', Str::ascii($name)), 0, 3)),
        ]);
        $this->teamsByCountry[$league->country]->push($team);
        $summary['teams_created']++;
        Log::info('Fixture calendar: created team not in seed', [
            'league' => $league->code,
            'team' => $name,
        ]);

        return $team;
    }

    /**
     * @return list<string>
     */
    private function tokens(?string $name): array
    {
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(Str::lower((string) $name)));
        $tokens = array_values(array_filter(
            explode(' ', $ascii),
            fn (string $token) => $token !== ''
                && ! ctype_digit($token)
                && ! in_array($token, self::STOP_TOKENS, true),
        ));
        sort($tokens);

        return $tokens;
    }
}
