<?php

namespace App\Http\Controllers;

use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\PlayerMatchStat;
use App\Models\TeamProfile;
use App\Services\Odds\ValueBets;
use Inertia\Inertia;
use Inertia\Response;

class MatchDetailController extends Controller
{
    public function __construct(private ValueBets $valueBets) {}

    /**
     * Full prediction breakdown for one fixture: Best Bet hero, every market
     * line, the model inputs panel (profiles, referee, xG trend), and recent
     * head-to-head meetings.
     */
    public function __invoke(Fixture $fixture): Response
    {
        $fixture->load(['league:id,code,name', 'homeTeam', 'awayTeam', 'referee', 'odds']);

        $prediction = $fixture->predictions()
            ->champion()
            ->orderByDesc('generated_at')
            ->with('markets')
            ->first();

        $displayTz = config('africode.display_timezone');

        return Inertia::render('MatchDetail', [
            'fixture' => [
                'id' => $fixture->id,
                'league' => $fixture->league->only(['code', 'name']),
                'season' => $fixture->season,
                'matchday' => $fixture->matchday,
                'status' => $fixture->status,
                'is_derby' => $fixture->is_derby,
                'kickoff_date' => $fixture->kickoff_utc->timezone($displayTz)->isoFormat('dddd D MMMM YYYY'),
                // Some published calendars carry the date but a placeholder
                // time; showing "TBC" beats showing a time that is wrong.
                'kickoff_time' => $fixture->kickoff_confirmed
                    ? $fixture->kickoff_utc->timezone($displayTz)->format('H:i')
                    : 'TBC',
                'home_goals' => $fixture->home_goals,
                'away_goals' => $fixture->away_goals,
                'home_team' => $fixture->homeTeam->only(['id', 'name', 'short_name', 'logo_url']),
                'away_team' => $fixture->awayTeam->only(['id', 'name', 'short_name', 'logo_url']),
            ],
            'referee' => $fixture->referee?->only([
                'name', 'matches_officiated', 'avg_yellows_per_match',
                'avg_reds_per_match', 'avg_fouls_per_match',
            ]),
            'prediction' => $prediction === null ? null : [
                'generated_at' => $prediction->generated_at->timezone($displayTz)->isoFormat('D MMM, HH:mm'),
                'model_version' => $prediction->model_version,
                'best_bet' => [
                    'headline' => $prediction->headline_text,
                    'market' => $prediction->best_bet_market,
                    'line' => $prediction->best_bet_line,
                    'direction' => $prediction->best_bet_direction,
                    'probability' => (float) $prediction->best_bet_probability,
                ],
                'markets' => $prediction->markets->map(fn ($market) => [
                    'market' => $market->market,
                    'line' => $market->line !== null ? (float) $market->line : null,
                    'direction' => $market->direction,
                    'probability' => (float) $market->probability,
                    'outcome' => $market->outcome,
                ])->values(),
            ],
            'value_bets' => $this->valueBets->compare($prediction, $fixture->odds),
            'odds' => $fixture->odds?->only([
                'home_odds', 'draw_odds', 'away_odds', 'over25_odds', 'under25_odds',
            ]),
            'profiles' => [
                'home' => $this->profilePayload($fixture->home_team_id, $fixture->season),
                'away' => $this->profilePayload($fixture->away_team_id, $fixture->season),
            ],
            'xg_trend' => [
                'home' => $this->xgTrend($fixture->home_team_id),
                'away' => $this->xgTrend($fixture->away_team_id),
            ],
            'head_to_head' => $this->headToHead($fixture, $displayTz),
            'key_players' => [
                'home' => $this->keyPlayers($fixture->home_team_id, $fixture->season),
                'away' => $this->keyPlayers($fixture->away_team_id, $fixture->season),
            ],
        ]);
    }

    /**
     * The team's top scorer, top assister, and most-carded player this
     * season. Rows are attributed to the team the player appeared for in
     * each match (player_match_stats.team_id), so mid-season transfers
     * don't leak stats between clubs. Null while the player backfill has
     * not reached this team/season yet.
     */
    private function keyPlayers(int $teamId, string $season): ?array
    {
        $totals = PlayerMatchStat::query()
            ->join('fixtures', 'fixtures.id', '=', 'player_match_stats.fixture_id')
            ->join('players', 'players.id', '=', 'player_match_stats.player_id')
            ->where('player_match_stats.team_id', $teamId)
            ->where('fixtures.season', $season)
            ->groupBy('players.id', 'players.name')
            ->selectRaw('players.name as name')
            ->selectRaw('SUM(COALESCE(player_match_stats.goals, 0)) as goals')
            ->selectRaw('SUM(COALESCE(player_match_stats.assists, 0)) as assists')
            ->selectRaw('SUM(COALESCE(player_match_stats.yellows, 0) + COALESCE(player_match_stats.reds, 0)) as cards')
            ->get();

        if ($totals->isEmpty()) {
            return null;
        }

        $pick = function (string $stat) use ($totals): ?array {
            $best = $totals->sortByDesc($stat)->first();

            return (int) $best->{$stat} > 0
                ? ['name' => $best->name, 'value' => (int) $best->{$stat}]
                : null;
        };

        return [
            'top_scorer' => $pick('goals'),
            'top_assister' => $pick('assists'),
            'most_carded' => $pick('cards'),
        ];
    }

    private function profilePayload(int $teamId, string $season): ?array
    {
        $profile = TeamProfile::where('team_id', $teamId)->where('season', $season)->first()
            ?? TeamProfile::where('team_id', $teamId)->orderByDesc('season')->first();

        return $profile?->only([
            'season', 'matches_played', 'attack_strength', 'defence_strength',
            'xg_for_avg', 'xg_against_avg', 'corners_for_avg', 'corners_against_avg',
            'crosses_avg', 'cards_avg', 'fouls_committed_avg', 'fouls_drawn_avg',
            'sot_for_avg', 'sot_against_avg', 'home_advantage_factor',
        ]);
    }

    /**
     * xG of the team's last 8 finished matches, oldest first (sparkline data).
     */
    private function xgTrend(int $teamId): array
    {
        return MatchStat::query()
            ->join('fixtures', 'fixtures.id', '=', 'match_stats.fixture_id')
            ->where('match_stats.team_id', $teamId)
            ->where('fixtures.status', Fixture::STATUS_FINISHED)
            ->whereNotNull('match_stats.xg')
            ->orderByDesc('fixtures.kickoff_utc')
            ->limit(8)
            ->pluck('match_stats.xg')
            ->map(fn ($xg) => (float) $xg)
            ->reverse()
            ->values()
            ->all();
    }

    private function headToHead(Fixture $fixture, string $displayTz): array
    {
        $teamIds = [$fixture->home_team_id, $fixture->away_team_id];

        return Fixture::query()
            ->finished()
            ->whereKeyNot($fixture->id)
            ->whereIn('home_team_id', $teamIds)
            ->whereIn('away_team_id', $teamIds)
            ->with(['homeTeam:id,name,short_name', 'awayTeam:id,name,short_name'])
            ->orderByDesc('kickoff_utc')
            ->limit(5)
            ->get()
            ->map(fn (Fixture $meeting) => [
                'date' => $meeting->kickoff_utc->timezone($displayTz)->isoFormat('D MMM YYYY'),
                'season' => $meeting->season,
                'home' => $meeting->homeTeam->short_name,
                'away' => $meeting->awayTeam->short_name,
                'home_goals' => $meeting->home_goals,
                'away_goals' => $meeting->away_goals,
            ])
            ->values()
            ->all();
    }
}
