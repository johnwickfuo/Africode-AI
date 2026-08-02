<?php

namespace App\Services\Odds;

use App\Models\Fixture;
use App\Models\FixtureOdds;
use App\Models\League;
use App\Models\Rivalry;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Imports bookmaker odds for UPCOMING fixtures from football-data.co.uk's
 * free fixtures.csv (one file covering every league, refreshed daily, no
 * key, no Cloudflare). Market-average columns preferred, Bet365 fallback.
 *
 * These odds power the value-bet comparison: model probability vs the
 * market's overround-stripped implied probability. Historical odds for
 * finished matches arrive separately via the results-CSV import.
 */
class ImportOddsService
{
    public const CSV_URL = 'https://www.football-data.co.uk/fixtures.csv';

    // Divisions come from the league rows (leagues.fdcouk_code): every
    // league with a CSV division is priced here, including the ones whose
    // upcoming fixtures this file is also the only source for.

    /** Same alias map as the results-CSV importer — same site, same names. */
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

    /** @var array<string, Collection<int, Team>> keyed by country */
    private array $teamsByCountry = [];

    /**
     * @return array{rows_seen: int, odds_saved: int, fixtures_unmatched: int}
     */
    public function run(): array
    {
        $summary = ['rows_seen' => 0, 'odds_saved' => 0, 'fixtures_created' => 0, 'fixtures_unmatched' => 0];

        $rows = $this->download();
        $leagues = League::whereNotNull('fdcouk_code')->get()->keyBy('fdcouk_code');

        foreach ($rows as $row) {
            $league = $leagues->get($row['Div'] ?? '');
            if ($league === null || blank($row['HomeTeam'] ?? null) || blank($row['AwayTeam'] ?? null)) {
                continue;
            }
            $summary['rows_seen']++;

            $home = $this->resolveTeam($league, $row['HomeTeam']);
            $away = $this->resolveTeam($league, $row['AwayTeam']);
            $fixture = ($home && $away) ? Fixture::query()
                ->where('league_id', $league->id)
                ->where('home_team_id', $home->id)
                ->where('away_team_id', $away->id)
                ->where('kickoff_utc', '>=', now('UTC')->subDay())
                ->orderBy('kickoff_utc')
                ->first() : null;

            // Leagues outside the football-data.org free tier have no other
            // upcoming-fixture feed, so this file creates them. Leagues the
            // API does cover are left alone — the sync owns their kickoff
            // times and matchdays.
            if ($fixture === null && $home && $away && $league->footballdata_code === null) {
                $kickoff = $this->kickoff($row);
                if ($kickoff !== null) {
                    $fixture = Fixture::create([
                        'league_id' => $league->id,
                        'season' => $this->seasonLabel($kickoff),
                        'home_team_id' => $home->id,
                        'away_team_id' => $away->id,
                        'kickoff_utc' => $kickoff,
                        'status' => Fixture::STATUS_SCHEDULED,
                        'is_derby' => Rivalry::isDerbyPair($home->id, $away->id),
                    ]);
                    $summary['fixtures_created']++;
                }
            }

            if ($fixture === null) {
                $summary['fixtures_unmatched']++;

                continue;
            }

            $odds = $this->extractOdds($row);
            if ($odds === []) {
                continue;
            }

            FixtureOdds::updateOrCreate(
                ['fixture_id' => $fixture->id],
                $odds + ['source' => 'fdcouk', 'fetched_at' => now()],
            );
            $summary['odds_saved']++;
        }

        Log::info('Odds import finished', $summary);

        return $summary;
    }

    /**
     * Market-average odds when present, Bet365 otherwise. Absent markets
     * stay null; a row with no odds at all is skipped.
     *
     * @param  array<string, string>  $row
     * @return array<string, float>
     */
    private function extractOdds(array $row): array
    {
        $pick = function (array $columns) use ($row): ?float {
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (is_numeric($value) && (float) $value > 1.0) {
                    return (float) $value;
                }
            }

            return null;
        };

        $odds = array_filter([
            'home_odds' => $pick(['AvgH', 'B365H']),
            'draw_odds' => $pick(['AvgD', 'B365D']),
            'away_odds' => $pick(['AvgA', 'B365A']),
            'over25_odds' => $pick(['Avg>2.5', 'B365>2.5']),
            'under25_odds' => $pick(['Avg<2.5', 'B365<2.5']),
        ], fn ($value) => $value !== null);

        // 1X2 must be complete to be usable; O/U alone is still worth saving.
        if (! isset($odds['home_odds'], $odds['draw_odds'], $odds['away_odds'])) {
            unset($odds['home_odds'], $odds['draw_odds'], $odds['away_odds']);
        }

        return $odds;
    }

    /**
     * fixtures.csv dates are dd/mm/yyyy with a separate HH:MM column, in UK
     * time — close enough to UTC for a kickoff the fixture sync will correct
     * on API-covered leagues and that only needs day precision elsewhere.
     */
    private function kickoff(array $row): ?Carbon
    {
        $date = trim((string) ($row['Date'] ?? ''));
        if ($date === '') {
            return null;
        }

        $time = filled($row['Time'] ?? null) ? $row['Time'] : '15:00';
        $format = strlen($date) === 8 ? 'd/m/y H:i' : 'd/m/Y H:i';

        try {
            return Carbon::createFromFormat($format, "{$date} {$time}", 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * European seasons start in July, so a August-to-December kickoff opens
     * the season named for that year.
     */
    private function seasonLabel(Carbon $kickoff): string
    {
        $start = $kickoff->month >= 7 ? $kickoff->year : $kickoff->year - 1;

        return $start.'-'.($start + 1);
    }

    /**
     * @return list<array<string, string>>
     */
    private function download(): array
    {
        $body = ltrim(Http::timeout(30)->retry(3, 2000, throw: true)
            ->get(self::CSV_URL)->throw()->body(), "\u{FEFF}");

        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        if (count($lines) < 2) {
            return [];
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

    private function resolveTeam(League $league, string $csvName): ?Team
    {
        // Same country-wide pool as the stats import, so a club that moved
        // division still resolves to its existing row.
        $teams = $this->teamsByCountry[$league->country] ??= Team::query()
            ->whereHas('league', fn ($query) => $query->where('country', $league->country))
            ->get();

        $direct = $teams->first(fn (Team $team) => $team->fdcouk_name !== null
            && strcasecmp($team->fdcouk_name, $csvName) === 0);
        if ($direct) {
            return $direct;
        }

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

        return $subset->count() === 1 ? $subset->first() : null;
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
