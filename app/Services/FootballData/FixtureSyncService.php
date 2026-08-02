<?php

namespace App\Services\FootballData;

use App\Models\Fixture;
use App\Models\League;
use App\Models\Referee;
use App\Models\Rivalry;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Syncs fixtures & results from football-data.org into the fixtures table:
 * upcoming fixtures for the next 14 days plus results for the last 3 days,
 * for all five tracked leagues — one request per league (5 total per run).
 *
 * Teams are resolved by stored footballdata_id first, then by name matching;
 * a successful name match backfills teams.footballdata_id (and the crest as
 * logo_url) so future runs match by id. Unresolvable matches are logged and
 * skipped — a bad row must never abort the sync.
 */
class FixtureSyncService
{
    public const DAYS_BACK = 3;

    public const DAYS_AHEAD = 14;

    /**
     * Tokens carrying no identity: legal-form/club suffixes and connectives
     * that football-data.org includes but our seeded names may not.
     */
    private const STOP_TOKENS = [
        'fc', 'afc', 'cf', 'cfc', 'acf', 'ac', 'as', 'aj', 'ss', 'ssc', 'us',
        'rc', 'rcd', 'ca', 'cd', 'ud', 'sc', 'sco', 'sv', 'fsv', 'vfl', 'vfb',
        'tsg', 'bc', 'ogc', 'losc', 'osc', 'club', 'de', 'calcio', 'balompie',
    ];

    /** Normalized football-data.org name => seeded team name, for pairs no rule can bridge. */
    private const NAME_ALIASES = [
        'internazionale milano' => 'Inter Milan',
        'bayern munchen' => 'Bayern Munich',
    ];

    public function __construct(private FootballDataClient $client) {}

    /**
     * @return array{fixtures_created: int, fixtures_updated: int, matches_skipped: int}
     */
    public function run(): array
    {
        $summary = ['fixtures_created' => 0, 'fixtures_updated' => 0, 'matches_skipped' => 0, 'teams_created' => 0];

        $from = now('UTC')->subDays(self::DAYS_BACK)->startOfDay();
        $to = now('UTC')->addDays(self::DAYS_AHEAD)->endOfDay();

        // Only leagues the football-data.org plan actually carries. The rest
        // take their upcoming fixtures from football-data.co.uk's
        // fixtures.csv during the odds import.
        $leagues = League::whereNotNull('footballdata_code')->orderBy('id')->get();

        foreach ($leagues as $league) {
            $matches = $this->client->competitionMatches($league->footballdata_code, $from, $to);
            // Country-wide pool: a club promoted or relegated between two
            // tracked divisions keeps its existing row.
            $teams = Team::query()
                ->whereHas('league', fn ($query) => $query->where('country', $league->country))
                ->get();

            foreach ($matches as $match) {
                $home = $this->resolveTeam($match['homeTeam'] ?? [], $league, $teams, $summary);
                $away = $this->resolveTeam($match['awayTeam'] ?? [], $league, $teams, $summary);

                if ($home === null || $away === null) {
                    $summary['matches_skipped']++;
                    Log::warning('Fixture sync: match payload has no usable team, skipped', [
                        'league' => $league->code,
                        'match_id' => $match['id'] ?? null,
                    ]);

                    continue;
                }

                $fixture = $this->upsertFixture($league, $match, $home, $away);

                if ($fixture->wasRecentlyCreated) {
                    $summary['fixtures_created']++;
                } elseif ($fixture->wasChanged()) {
                    $summary['fixtures_updated']++;
                }
            }
        }

        Log::info('football-data.org fixture sync finished', $summary);

        return $summary;
    }

    private function upsertFixture(League $league, array $match, Team $home, Team $away): Fixture
    {
        $status = $this->mapStatus($match['status'] ?? 'SCHEDULED');

        $attributes = [
            'league_id' => $league->id,
            'season' => $this->seasonLabel($match),
            'matchday' => $match['matchday'] ?? null,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'kickoff_utc' => Carbon::parse($match['utcDate'])->utc(),
            'status' => $status,
            'is_derby' => Rivalry::isDerbyPair($home->id, $away->id),
        ];

        if ($status === Fixture::STATUS_FINISHED) {
            $attributes['home_goals'] = $match['score']['fullTime']['home'] ?? null;
            $attributes['away_goals'] = $match['score']['fullTime']['away'] ?? null;
        }

        // Referees are only assigned ~48h before kickoff; never null out a
        // previously stored assignment just because the API omits it today.
        $refereeName = collect($match['referees'] ?? [])->firstWhere('type', 'REFEREE')['name'] ?? null;
        if ($refereeName !== null) {
            $attributes['referee_id'] = Referee::firstOrCreate(['name' => $refereeName])->id;
        }

        return Fixture::updateOrCreate(
            ['footballdata_match_id' => $match['id']],
            $attributes,
        );
    }

    private function mapStatus(string $apiStatus): string
    {
        return match ($apiStatus) {
            'FINISHED', 'AWARDED' => Fixture::STATUS_FINISHED,
            'POSTPONED', 'SUSPENDED', 'CANCELLED' => Fixture::STATUS_POSTPONED,
            // SCHEDULED / TIMED / IN_PLAY / PAUSED: in-play matches stay
            // "scheduled" and flip to finished on the next sync.
            default => Fixture::STATUS_SCHEDULED,
        };
    }

    private function seasonLabel(array $match): string
    {
        $start = $match['season']['startDate'] ?? null;
        $end = $match['season']['endDate'] ?? null;

        if ($start !== null && $end !== null) {
            return Carbon::parse($start)->year.'-'.Carbon::parse($end)->year;
        }

        // Fallback: infer a European season from kickoff month (Aug-May).
        $kickoff = Carbon::parse($match['utcDate']);

        return $kickoff->month >= 8
            ? $kickoff->year.'-'.($kickoff->year + 1)
            : ($kickoff->year - 1).'-'.$kickoff->year;
    }

    /**
     * @param  Collection<int, Team>  $teams  the league's teams (created teams are appended)
     */
    private function resolveTeam(array $apiTeam, League $league, Collection $teams, array &$summary): ?Team
    {
        $team = $this->matchTeam($apiTeam, $teams);

        if ($team === null) {
            if (blank($apiTeam['name'] ?? null)) {
                return null;
            }

            // Unknown club — a promoted side after a season rollover. Create
            // it so its fixtures flow immediately; fbref_name is a best guess
            // that the FBref importer corrects on first match data.
            $team = Team::create([
                'league_id' => $league->id,
                'name' => preg_replace('/\s+(A?FC|CF)$/i', '', $apiTeam['name']),
                'fbref_name' => $apiTeam['shortName'] ?? $apiTeam['name'],
                'short_name' => $apiTeam['tla'] ?? Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', Str::ascii($apiTeam['name'])), 0, 3)),
                'footballdata_id' => $apiTeam['id'] ?? null,
                'logo_url' => $apiTeam['crest'] ?? null,
            ]);
            $teams->push($team);
            $summary['teams_created']++;
            Log::info('Fixture sync: created team not in seed (promoted side?)', [
                'league' => $league->code,
                'team' => $team->name,
            ]);

            return $team;
        }

        // Backfill ids/crest learned from the API so future runs match by id.
        $team->footballdata_id = $apiTeam['id'] ?? $team->footballdata_id;
        if (blank($team->logo_url) && filled($apiTeam['crest'] ?? null)) {
            $team->logo_url = $apiTeam['crest'];
        }
        if ($team->isDirty()) {
            $team->save();
        }

        return $team;
    }

    /**
     * @param  Collection<int, Team>  $teams
     */
    private function matchTeam(array $apiTeam, Collection $teams): ?Team
    {
        if ($apiTeam === []) {
            return null;
        }

        // 1. Stored football-data.org id (the steady state after first sync).
        if (isset($apiTeam['id']) && ($team = $teams->firstWhere('footballdata_id', $apiTeam['id']))) {
            return $team;
        }

        $candidates = array_values(array_filter([$apiTeam['name'] ?? null, $apiTeam['shortName'] ?? null]));

        // 2. Explicit alias (e.g. "FC Internazionale Milano" => Inter Milan).
        foreach ($candidates as $candidate) {
            $alias = self::NAME_ALIASES[implode(' ', $this->tokens($candidate))] ?? null;
            if ($alias !== null && ($team = $teams->firstWhere('name', $alias))) {
                return $team;
            }
        }

        // 3. Exact normalized-token match against our name/fbref_name.
        foreach ($candidates as $candidate) {
            $tokens = $this->tokens($candidate);
            foreach ($teams as $team) {
                foreach ($this->teamTokenSets($team) as $set) {
                    if ($tokens === $set) {
                        return $team;
                    }
                }
            }
        }

        // 4. Unambiguous subset match, either direction — bridges affixes like
        // "RCD Espanyol de Barcelona" vs "Espanyol" or "RC Strasbourg Alsace"
        // vs "RC Strasbourg". Only accepted when exactly one team matches.
        foreach ($candidates as $candidate) {
            $tokens = $this->tokens($candidate);
            if ($tokens === []) {
                continue;
            }

            $matches = $teams->filter(function (Team $team) use ($tokens) {
                foreach ($this->teamTokenSets($team) as $set) {
                    if ($set !== [] && ($this->isSubset($tokens, $set) || $this->isSubset($set, $tokens))) {
                        return true;
                    }
                }

                return false;
            });

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        // 5. Three-letter abbreviation as a last resort.
        if (filled($apiTeam['tla'] ?? null)) {
            $byTla = $teams->filter(fn (Team $team) => strcasecmp($team->short_name, $apiTeam['tla']) === 0);
            if ($byTla->count() === 1) {
                return $byTla->first();
            }
        }

        return null;
    }

    /**
     * Normalized identity tokens: lowercase, accent-folded, punctuation-free,
     * with legal-form/connective tokens and standalone numbers removed.
     *
     * @return list<string>
     */
    private function tokens(string $name): array
    {
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(Str::lower($name)));

        $tokens = array_filter(
            explode(' ', $ascii),
            fn (string $token) => $token !== ''
                && ! ctype_digit($token)
                && ! in_array($token, self::STOP_TOKENS, true),
        );

        $tokens = array_values($tokens);
        sort($tokens);

        return $tokens;
    }

    /**
     * @return list<list<string>>
     */
    private function teamTokenSets(Team $team): array
    {
        return array_unique([
            $this->tokens($team->name),
            $this->tokens($team->fbref_name),
        ], SORT_REGULAR);
    }

    /**
     * @param  list<string>  $needle
     * @param  list<string>  $haystack
     */
    private function isSubset(array $needle, array $haystack): bool
    {
        return $needle !== [] && array_diff($needle, $haystack) === [];
    }
}
