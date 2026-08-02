<?php

namespace App\Services\Chat;

use App\Models\Fixture;
use App\Models\League;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\PredictionMarket;
use App\Models\Referee;
use App\Models\Team;
use App\Models\TeamProfile;
use App\Services\Odds\ValueBets;
use Illuminate\Support\Facades\Validator;

/**
 * The chat model's only window into the database: six whitelisted tools,
 * each a fixed Eloquent query over validated parameters. The model never
 * writes SQL, table names, or column names — only these tool names and
 * JSON arguments, which are validated before touching a query builder.
 */
class ChatToolbox
{
    /**
     * Tracked league codes, read from the database so adding a league needs
     * no code change. Cached per request — the toolbox is short-lived.
     *
     * @return list<string>
     */
    private function leagueCodes(): array
    {
        return $this->leagueCodes ??= League::orderBy('id')->pluck('code')->all();
    }

    /** @var list<string>|null */
    private ?array $leagueCodes = null;

    private function leagueDescription(): string
    {
        return 'League code, one of: '.implode(', ', $this->leagueCodes());
    }

    /**
     * Gemini function declarations for every tool.
     *
     * @return list<array<string, mixed>>
     */
    public function declarations(): array
    {
        $team = ['type' => 'STRING', 'description' => 'Team name, e.g. "Arsenal" or "Bayern"'];

        return [
            [
                'name' => 'get_team_stats',
                'description' => 'Team stats for one season: totals (matches, wins/draws/losses, goals scored and conceded, accumulated xG, corners, cards, clean sheets) plus the rolling model profile (attack/defence strength and per-match averages).',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'team' => $team,
                        'season' => ['type' => 'STRING', 'description' => 'Season like "2025-2026" (optional, defaults to latest)'],
                    ],
                    'required' => ['team'],
                ],
            ],
            [
                'name' => 'get_fixtures',
                'description' => 'Upcoming fixtures with kickoff times and Best Bet headlines. Filter by league and/or team.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'league' => ['type' => 'STRING', 'description' => $this->leagueDescription()],
                        'team' => $team,
                        'days' => ['type' => 'INTEGER', 'description' => 'How many days ahead to look, 1-14 (default 7)'],
                    ],
                ],
            ],
            [
                'name' => 'get_predictions',
                'description' => "Full market-by-market prediction for a team's next fixture (match result 1X2, goals, corners, cards, shots on target, BTTS) including the Best Bet, plus bookmaker odds and the model's value edge vs the market when available.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'team' => $team,
                        'opponent' => ['type' => 'STRING', 'description' => 'Optional opponent to pick a specific fixture'],
                    ],
                    'required' => ['team'],
                ],
            ],
            [
                'name' => 'get_h2h',
                'description' => 'Recent head-to-head meetings between two teams with scores.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'team1' => $team,
                        'team2' => $team,
                    ],
                    'required' => ['team1', 'team2'],
                ],
            ],
            [
                'name' => 'get_referee_profile',
                'description' => 'A referee\'s card profile: matches officiated, average yellows/reds/fouls per match.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING', 'description' => 'Referee name, e.g. "Michael Oliver"'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'get_player_stats',
                'description' => 'Season totals and per-match averages for a player: goals, assists, shots, shots on target, cards, minutes, xG, xA.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'player' => ['type' => 'STRING', 'description' => 'Player name or part of it, e.g. "Saka"'],
                        'season' => ['type' => 'STRING', 'description' => 'Season like "2025-2026" (optional, defaults to latest with data)'],
                    ],
                    'required' => ['player'],
                ],
            ],
            [
                'name' => 'get_top_players',
                'description' => 'Leaderboard of the top 10 players for a stat, optionally per league and season.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'stat' => ['type' => 'STRING', 'description' => 'One of: goals, assists, cards, shots_on_target'],
                        'league' => ['type' => 'STRING', 'description' => $this->leagueDescription()],
                        'season' => ['type' => 'STRING', 'description' => 'Season like "2025-2026" (optional, defaults to latest with data)'],
                    ],
                    'required' => ['stat'],
                ],
            ],
            [
                'name' => 'get_player_recent_form',
                'description' => "A player's last 5 matches with minutes, goals, assists, shots and cards per match.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'player' => ['type' => 'STRING', 'description' => 'Player name or part of it'],
                    ],
                    'required' => ['player'],
                ],
            ],
            [
                'name' => 'get_accuracy_stats',
                'description' => "The prediction model's own accuracy: hit rates of settled picks per market and for headline Best Bets.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'market' => ['type' => 'STRING', 'description' => 'Optional market filter: goals, corners, cards, shots_on_target, btts'],
                        'days' => ['type' => 'INTEGER', 'description' => 'Look-back window in days, 7-365 (default 90)'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Execute a whitelisted tool. Always returns an array (data or
     * ['error' => ...]) — never throws for bad model input.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(string $name, array $args): array
    {
        return match ($name) {
            'get_team_stats' => $this->getTeamStats($args),
            'get_fixtures' => $this->getFixtures($args),
            'get_predictions' => $this->getPredictions($args),
            'get_h2h' => $this->getH2h($args),
            'get_referee_profile' => $this->getRefereeProfile($args),
            'get_accuracy_stats' => $this->getAccuracyStats($args),
            'get_player_stats' => $this->getPlayerStats($args),
            'get_top_players' => $this->getTopPlayers($args),
            'get_player_recent_form' => $this->getPlayerRecentForm($args),
            default => ['error' => "Unknown tool '{$name}'."],
        };
    }

    private function validate(array $args, array $rules): ?array
    {
        $validator = Validator::make($args, $rules);

        return $validator->fails()
            ? ['error' => 'Invalid parameters: '.$validator->errors()->first()]
            : null;
    }

    private function findTeam(string $query): ?Team
    {
        $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($query)).'%';

        return Team::query()
            ->where('name', 'like', $needle)
            ->orWhere('short_name', 'like', $needle)
            ->orWhere('fbref_name', 'like', $needle)
            ->orderBy('id')
            ->first();
    }

    private function getTeamStats(array $args): array
    {
        if ($error = $this->validate($args, [
            'team' => 'required|string|max:60',
            'season' => 'nullable|string|regex:/^\d{4}-\d{4}$/',
        ])) {
            return $error;
        }

        $team = $this->findTeam($args['team']);
        if ($team === null) {
            return ['error' => "No team matching '{$args['team']}' in the tracked leagues."];
        }

        $profile = TeamProfile::where('team_id', $team->id)
            ->when(isset($args['season']), fn ($query) => $query->where('season', $args['season']))
            ->orderByDesc('season')
            ->first();

        $season = $args['season']
            ?? Fixture::finished()
                ->where(fn ($q) => $q->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))
                ->orderByDesc('season')
                ->value('season');

        return [
            'team' => $team->name,
            'league' => $team->league->name,
            'season_totals' => ($season ? $this->seasonTotals($team, $season) : null)
                ?? 'No finished matches in the data for that season.',
            'profile' => $profile?->only([
                'season', 'matches_played', 'attack_strength', 'defence_strength',
                'xg_for_avg', 'xg_against_avg', 'corners_for_avg', 'corners_against_avg',
                'crosses_avg', 'cards_avg', 'fouls_committed_avg', 'fouls_drawn_avg',
                'sot_for_avg', 'sot_against_avg', 'home_advantage_factor',
            ]) ?? 'No profile computed yet for this team.',
            'note' => 'Profile averages are exponentially weighted toward recent matches; season_totals are plain sums over finished league matches.',
        ];
    }

    /**
     * Plain sums over a team's finished league matches in one season —
     * results from the fixtures, everything else from match_stats rows.
     *
     * @return array<string, mixed>|null
     */
    private function seasonTotals(Team $team, string $season): ?array
    {
        $fixtures = Fixture::finished()
            ->where('season', $season)
            ->where(fn ($q) => $q->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))
            ->get(['id', 'home_team_id', 'home_goals', 'away_goals']);

        if ($fixtures->isEmpty()) {
            return null;
        }

        $results = ['wins' => 0, 'draws' => 0, 'losses' => 0, 'goals_for' => 0, 'goals_against' => 0, 'clean_sheets' => 0];
        foreach ($fixtures as $fixture) {
            $isHome = $fixture->home_team_id === $team->id;
            $for = $isHome ? $fixture->home_goals : $fixture->away_goals;
            $against = $isHome ? $fixture->away_goals : $fixture->home_goals;
            $results['goals_for'] += $for;
            $results['goals_against'] += $against;
            $results[$for <=> $against ? ($for > $against ? 'wins' : 'losses') : 'draws']++;
            $results['clean_sheets'] += $against === 0 ? 1 : 0;
        }

        $stats = $team->matchStats()->whereIn('fixture_id', $fixtures->pluck('id'))->get();
        $xgCovered = $stats->whereNotNull('xg')->count();

        return [
            'season' => $season,
            'played' => $fixtures->count(),
            ...$results,
            'xg_for' => $xgCovered ? round($stats->sum('xg'), 1) : null,
            'xg_against' => $xgCovered ? round($stats->sum('xga'), 1) : null,
            // xG is enriched progressively (Understat backfill) — flag
            // partial coverage so totals aren't read as full-season sums.
            'xg_matches_covered' => $xgCovered,
            'corners_for' => (int) $stats->sum('corners_for'),
            'yellows' => (int) $stats->sum('yellows'),
            'reds' => (int) $stats->sum('reds'),
            'shots_on_target' => (int) $stats->sum('shots_on_target'),
        ];
    }

    private function getFixtures(array $args): array
    {
        if ($error = $this->validate($args, [
            'league' => 'nullable|string|in:'.implode(',', $this->leagueCodes()),
            'team' => 'nullable|string|max:60',
            'days' => 'nullable|integer|min:1|max:14',
        ])) {
            return $error;
        }

        $team = null;
        if (filled($args['team'] ?? null)) {
            $team = $this->findTeam($args['team']);
            if ($team === null) {
                return ['error' => "No team matching '{$args['team']}' in the tracked leagues."];
            }
        }

        $fixtures = Fixture::upcoming()
            ->where('kickoff_utc', '<=', now('UTC')->addDays($args['days'] ?? 7))
            ->when(isset($args['league']), fn ($query) => $query->whereHas(
                'league', fn ($league) => $league->where('code', $args['league']),
            ))
            ->when($team, fn ($query) => $query->where(
                fn ($q) => $q->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id),
            ))
            ->with(['homeTeam:id,name', 'awayTeam:id,name', 'league:id,code',
                'predictions' => fn ($query) => $query->champion()->orderByDesc('generated_at')->limit(1)])
            ->limit(15)
            ->get()
            ->map(fn (Fixture $fixture) => [
                'league' => $fixture->league->code,
                'home' => $fixture->homeTeam->name,
                'away' => $fixture->awayTeam->name,
                'kickoff' => $fixture->kickoffLabel(),
                'derby' => $fixture->is_derby,
                'best_bet' => $fixture->predictions->first()?->headline_text,
            ]);

        return $fixtures->isEmpty()
            ? ['result' => 'No upcoming fixtures found for those filters.']
            : ['timezone' => config('africode.display_timezone'), 'fixtures' => $fixtures->all()];
    }

    private function getPredictions(array $args): array
    {
        if ($error = $this->validate($args, [
            'team' => 'required|string|max:60',
            'opponent' => 'nullable|string|max:60',
        ])) {
            return $error;
        }

        $team = $this->findTeam($args['team']);
        if ($team === null) {
            return ['error' => "No team matching '{$args['team']}' in the tracked leagues."];
        }

        $opponent = null;
        if (filled($args['opponent'] ?? null)) {
            $opponent = $this->findTeam($args['opponent']);
            if ($opponent === null) {
                return ['error' => "No team matching '{$args['opponent']}' in the tracked leagues."];
            }
        }

        $fixture = Fixture::upcoming()
            ->where(fn ($q) => $q->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))
            ->when($opponent, fn ($query) => $query->where(
                fn ($q) => $q->where('home_team_id', $opponent->id)->orWhere('away_team_id', $opponent->id),
            ))
            ->with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->first();

        if ($fixture === null) {
            return ['result' => "No upcoming fixture found for {$team->name}".($opponent ? " against {$opponent->name}" : '').'.'];
        }

        $prediction = $fixture->predictions()->champion()->orderByDesc('generated_at')->with('markets')->first();
        if ($prediction === null) {
            return ['result' => "{$fixture->homeTeam->name} v {$fixture->awayTeam->name} has no prediction yet (generated daily at 06:00 for fixtures within 7 days)."];
        }

        $valueBets = app(ValueBets::class)->compare($prediction, $fixture->odds);

        return [
            'fixture' => "{$fixture->homeTeam->name} v {$fixture->awayTeam->name}",
            'kickoff' => $fixture->kickoffLabel(),
            'derby' => $fixture->is_derby,
            'best_bet' => $prediction->headline_text,
            'markets' => $prediction->markets->map(fn (PredictionMarket $market) => [
                'market' => $market->market,
                'pick' => trim(($market->line !== null ? "{$market->direction} {$market->line}" : $market->direction)),
                'probability' => (float) $market->probability,
            ])->all(),
            'value_vs_market' => $valueBets === [] ? 'No bookmaker odds available for this fixture yet.' : $valueBets,
            'note' => 'Probabilities are model estimates, not guarantees. A positive edge means the model rates the pick higher than the bookmaker market does.',
        ];
    }

    private function getH2h(array $args): array
    {
        if ($error = $this->validate($args, [
            'team1' => 'required|string|max:60',
            'team2' => 'required|string|max:60',
        ])) {
            return $error;
        }

        $team1 = $this->findTeam($args['team1']);
        $team2 = $this->findTeam($args['team2']);
        if ($team1 === null || $team2 === null) {
            return ['error' => 'One or both teams not found in the tracked leagues.'];
        }

        $meetings = Fixture::finished()
            ->whereIn('home_team_id', [$team1->id, $team2->id])
            ->whereIn('away_team_id', [$team1->id, $team2->id])
            ->with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->orderByDesc('kickoff_utc')
            ->limit(5)
            ->get()
            ->map(fn (Fixture $fixture) => [
                'date' => $fixture->kickoff_utc->format('Y-m-d'),
                'season' => $fixture->season,
                'result' => "{$fixture->homeTeam->name} {$fixture->home_goals}-{$fixture->away_goals} {$fixture->awayTeam->name}",
            ]);

        return $meetings->isEmpty()
            ? ['result' => "No meetings between {$team1->name} and {$team2->name} in the data (2023-24 onwards)."]
            : ['meetings' => $meetings->all()];
    }

    private function getRefereeProfile(array $args): array
    {
        if ($error = $this->validate($args, ['name' => 'required|string|max:60'])) {
            return $error;
        }

        $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($args['name'])).'%';
        $referee = Referee::where('name', 'like', $needle)->first();

        if ($referee === null) {
            return ['error' => "No referee matching '{$args['name']}' in the data."];
        }

        return $referee->only([
            'name', 'matches_officiated', 'avg_yellows_per_match',
            'avg_reds_per_match', 'avg_fouls_per_match',
        ]);
    }

    private function getAccuracyStats(array $args): array
    {
        if ($error = $this->validate($args, [
            'market' => 'nullable|string|max:32',
            'days' => 'nullable|integer|min:7|max:365',
        ])) {
            return $error;
        }

        $settled = PredictionMarket::query()
            ->whereIn('outcome', [PredictionMarket::OUTCOME_WON, PredictionMarket::OUTCOME_LOST])
            ->where('settled_at', '>=', now()->subDays($args['days'] ?? 90))
            ->when(filled($args['market'] ?? null), fn ($query) => $query->where('market', 'like', $args['market'].'%'))
            ->get(['market', 'probability', 'outcome']);

        if ($settled->isEmpty()) {
            return ['result' => 'No settled predictions in that window yet — accuracy accumulates as matches finish.'];
        }

        $perMarket = $settled->groupBy('market')->map(function ($rows, $market) {
            $hits = $rows->where('outcome', PredictionMarket::OUTCOME_WON)->count();

            return [
                'market' => $market,
                'settled' => $rows->count(),
                'hit_rate' => round($hits / $rows->count(), 3),
                'avg_claimed_probability' => round($rows->avg('probability'), 3),
            ];
        })->values()->all();

        // Calibration: claimed-probability buckets vs realised hit rate —
        // string keys because PHP truncates float array keys.
        $calibration = $settled->groupBy(fn ($row) => sprintf('%.2f', min(floor($row->probability * 20) / 20, 0.95)))
            ->map(function ($rows, $bucket) {
                $from = (float) $bucket;

                return [
                    'claimed' => sprintf('%.0f-%.0f%%', $from * 100, ($from + 0.05) * 100),
                    'settled' => $rows->count(),
                    'actual_hit_rate' => round($rows->where('outcome', PredictionMarket::OUTCOME_WON)->count() / $rows->count(), 3),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();

        return [
            'window_days' => $args['days'] ?? 90,
            'total_settled' => $settled->count(),
            'overall_hit_rate' => round($settled->where('outcome', PredictionMarket::OUTCOME_WON)->count() / $settled->count(), 3),
            'per_market' => $perMarket,
            'calibration' => $calibration,
            'calibration_note' => 'Well-calibrated means actual_hit_rate ≈ the claimed range.',
        ];
    }

    private function findPlayer(string $query): ?Player
    {
        $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($query)).'%';

        // Prefer the shortest matching name: "Saka" should hit "Bukayo Saka"
        // even if a longer partial match exists.
        return Player::query()
            ->where('name', 'like', $needle)
            ->orderByRaw('length(name)')
            ->orderBy('id')
            ->first();
    }

    private const BACKFILL_NOTE = 'Player history is backfilled progressively (newest matches first) — older matches may not be covered yet.';

    private function getPlayerStats(array $args): array
    {
        if ($error = $this->validate($args, [
            'player' => 'required|string|max:60',
            'season' => 'nullable|string|regex:/^\d{4}-\d{4}$/',
        ])) {
            return $error;
        }

        $player = $this->findPlayer($args['player']);
        if ($player === null) {
            return ['error' => "No player matching '{$args['player']}' in the data. ".self::BACKFILL_NOTE];
        }

        $season = $args['season'] ?? $this->latestSeasonWithData($player);
        if ($season === null) {
            return ['result' => "{$player->name} has no match data imported yet. ".self::BACKFILL_NOTE];
        }

        $rows = PlayerMatchStat::query()
            ->join('fixtures', 'fixtures.id', '=', 'player_match_stats.fixture_id')
            ->where('player_match_stats.player_id', $player->id)
            ->where('fixtures.season', $season)
            ->get(['player_match_stats.*']);

        if ($rows->isEmpty()) {
            return ['result' => "No {$season} match data for {$player->name}. ".self::BACKFILL_NOTE];
        }

        $matches = $rows->count();
        $totals = [
            'matches' => $matches,
            'minutes' => (int) $rows->sum('minutes'),
            'goals' => (int) $rows->sum('goals'),
            'assists' => (int) $rows->sum('assists'),
            'shots' => (int) $rows->sum('shots'),
            'shots_on_target' => (int) $rows->sum('shots_on_target'),
            'yellows' => (int) $rows->sum('yellows'),
            'reds' => (int) $rows->sum('reds'),
            'xg' => round($rows->sum('xg'), 2),
            'xa' => round($rows->sum('xa'), 2),
        ];

        return [
            'player' => $player->name,
            'team' => $player->team->name,
            'position' => $player->position,
            'nationality' => $player->nationality,
            'season' => $season,
            'totals' => $totals,
            'per_match' => collect($totals)->except('matches')
                ->map(fn ($value) => round($value / $matches, 2))->all(),
            'note' => self::BACKFILL_NOTE,
        ];
    }

    private function getTopPlayers(array $args): array
    {
        if ($error = $this->validate($args, [
            'stat' => 'required|string|in:goals,assists,cards,shots_on_target',
            'league' => 'nullable|string|in:'.implode(',', $this->leagueCodes()),
            'season' => 'nullable|string|regex:/^\d{4}-\d{4}$/',
        ])) {
            return $error;
        }

        $statColumn = match ($args['stat']) {
            'cards' => 'COALESCE(player_match_stats.yellows, 0) + COALESCE(player_match_stats.reds, 0)',
            default => "COALESCE(player_match_stats.{$args['stat']}, 0)",
        };

        $query = PlayerMatchStat::query()
            ->join('fixtures', 'fixtures.id', '=', 'player_match_stats.fixture_id')
            ->join('players', 'players.id', '=', 'player_match_stats.player_id')
            ->when(isset($args['league']), function ($query) use ($args) {
                $leagueId = League::where('code', $args['league'])->value('id');
                $query->where('fixtures.league_id', $leagueId);
            });

        $season = $args['season']
            ?? (clone $query)->orderByDesc('fixtures.season')->value('fixtures.season');

        if ($season === null) {
            return ['result' => 'No player data imported yet. '.self::BACKFILL_NOTE];
        }

        $leaders = $query
            ->where('fixtures.season', $season)
            ->groupBy('players.id', 'players.name')
            ->selectRaw('players.id as player_id, players.name as name')
            ->selectRaw("SUM({$statColumn}) as total")
            ->selectRaw('COUNT(*) as matches')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        if ($leaders->isEmpty() || (int) $leaders->first()->total === 0) {
            return ['result' => "No {$args['stat']} data for {$season} yet. ".self::BACKFILL_NOTE];
        }

        $teams = Player::whereIn('id', $leaders->pluck('player_id'))->with('team:id,name')->get()->keyBy('id');

        return [
            'stat' => $args['stat'],
            'season' => $season,
            'league' => $args['league'] ?? 'all tracked leagues',
            'leaders' => $leaders->map(fn ($row) => [
                'player' => $row->name,
                'team' => $teams[$row->player_id]?->team?->name,
                'total' => (int) $row->total,
                'matches' => (int) $row->matches,
            ])->all(),
            'note' => self::BACKFILL_NOTE,
        ];
    }

    private function getPlayerRecentForm(array $args): array
    {
        if ($error = $this->validate($args, ['player' => 'required|string|max:60'])) {
            return $error;
        }

        $player = $this->findPlayer($args['player']);
        if ($player === null) {
            return ['error' => "No player matching '{$args['player']}' in the data. ".self::BACKFILL_NOTE];
        }

        $rows = PlayerMatchStat::query()
            ->where('player_id', $player->id)
            ->with(['fixture.homeTeam:id,name', 'fixture.awayTeam:id,name'])
            ->join('fixtures', 'fixtures.id', '=', 'player_match_stats.fixture_id')
            ->orderByDesc('fixtures.kickoff_utc')
            ->limit(5)
            ->get(['player_match_stats.*']);

        if ($rows->isEmpty()) {
            return ['result' => "No match data for {$player->name} yet. ".self::BACKFILL_NOTE];
        }

        return [
            'player' => $player->name,
            'team' => $player->team->name,
            'last_matches' => $rows->map(fn (PlayerMatchStat $row) => [
                'date' => $row->fixture->kickoff_utc->format('Y-m-d'),
                'match' => "{$row->fixture->homeTeam->name} {$row->fixture->home_goals}-{$row->fixture->away_goals} {$row->fixture->awayTeam->name}",
                'minutes' => $row->minutes,
                'goals' => $row->goals,
                'assists' => $row->assists,
                'shots' => $row->shots,
                'shots_on_target' => $row->shots_on_target,
                'cards' => ($row->yellows ?? 0) + ($row->reds ?? 0),
                'xg' => $row->xg,
            ])->all(),
            'note' => self::BACKFILL_NOTE,
        ];
    }

    private function latestSeasonWithData(Player $player): ?string
    {
        return PlayerMatchStat::query()
            ->join('fixtures', 'fixtures.id', '=', 'player_match_stats.fixture_id')
            ->where('player_match_stats.player_id', $player->id)
            ->orderByDesc('fixtures.season')
            ->value('fixtures.season');
    }
}
