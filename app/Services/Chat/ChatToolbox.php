<?php

namespace App\Services\Chat;

use App\Models\Fixture;
use App\Models\League;
use App\Models\PredictionMarket;
use App\Models\Referee;
use App\Models\Team;
use App\Models\TeamProfile;
use Illuminate\Support\Facades\Validator;

/**
 * The chat model's only window into the database: six whitelisted tools,
 * each a fixed Eloquent query over validated parameters. The model never
 * writes SQL, table names, or column names — only these tool names and
 * JSON arguments, which are validated before touching a query builder.
 */
class ChatToolbox
{
    private const LEAGUE_CODES = ['PL', 'PD', 'SA', 'BL1', 'FL1'];

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
                'description' => 'Rolling stat profile for a team (attack/defence strength, xG, corners, cards, fouls, shots on target averages).',
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
                        'league' => ['type' => 'STRING', 'description' => 'League code: PL, PD (La Liga), SA (Serie A), BL1 (Bundesliga), FL1 (Ligue 1)'],
                        'team' => $team,
                        'days' => ['type' => 'INTEGER', 'description' => 'How many days ahead to look, 1-14 (default 7)'],
                    ],
                ],
            ],
            [
                'name' => 'get_predictions',
                'description' => "Full market-by-market prediction for a team's next fixture (goals, corners, cards, shots on target, BTTS) including the Best Bet.",
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

        return [
            'team' => $team->name,
            'league' => $team->league->name,
            'profile' => $profile?->only([
                'season', 'matches_played', 'attack_strength', 'defence_strength',
                'xg_for_avg', 'xg_against_avg', 'corners_for_avg', 'corners_against_avg',
                'crosses_avg', 'cards_avg', 'fouls_committed_avg', 'fouls_drawn_avg',
                'sot_for_avg', 'sot_against_avg', 'home_advantage_factor',
            ]) ?? 'No profile computed yet for this team.',
            'note' => 'Averages are exponentially weighted toward recent matches.',
        ];
    }

    private function getFixtures(array $args): array
    {
        if ($error = $this->validate($args, [
            'league' => 'nullable|string|in:'.implode(',', self::LEAGUE_CODES),
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
                'predictions' => fn ($query) => $query->orderByDesc('generated_at')->limit(1)])
            ->limit(15)
            ->get()
            ->map(fn (Fixture $fixture) => [
                'league' => $fixture->league->code,
                'home' => $fixture->homeTeam->name,
                'away' => $fixture->awayTeam->name,
                'kickoff' => $fixture->kickoff_utc->timezone(config('africode.display_timezone'))->format('D j M H:i'),
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

        $prediction = $fixture->predictions()->orderByDesc('generated_at')->with('markets')->first();
        if ($prediction === null) {
            return ['result' => "{$fixture->homeTeam->name} v {$fixture->awayTeam->name} has no prediction yet (generated daily at 06:00 for fixtures within 7 days)."];
        }

        return [
            'fixture' => "{$fixture->homeTeam->name} v {$fixture->awayTeam->name}",
            'kickoff' => $fixture->kickoff_utc->timezone(config('africode.display_timezone'))->format('D j M H:i'),
            'derby' => $fixture->is_derby,
            'best_bet' => $prediction->headline_text,
            'markets' => $prediction->markets->map(fn (PredictionMarket $market) => [
                'market' => $market->market,
                'pick' => trim(($market->line !== null ? "{$market->direction} {$market->line}" : $market->direction)),
                'probability' => (float) $market->probability,
            ])->all(),
            'note' => 'Probabilities are model estimates, not guarantees.',
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

        return [
            'window_days' => $args['days'] ?? 90,
            'total_settled' => $settled->count(),
            'overall_hit_rate' => round($settled->where('outcome', PredictionMarket::OUTCOME_WON)->count() / $settled->count(), 3),
            'per_market' => $perMarket,
        ];
    }
}
