<?php

namespace App\Services\Profiles;

use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\Referee;
use App\Models\TeamProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Nightly recomputation of rolling profiles from match_stats (spec 5 + 7.1).
 *
 * Team profiles: one row per team per season. Every aggregate is
 * exponentially weighted by recency — a match k matches ago weighs
 * 0.5^(k / 8) (half-life ~8 matches). Each season's window also includes
 * the previous season's matches so early-season rows aren't starved: with
 * the half-life, a full season back only weighs ~4%, so the window
 * converges to "this season's form" as matchdays accumulate.
 *
 * Attack/defence strengths are recency-weighted ratios vs the league
 * average, on a 70% xG / 30% goals blend to reduce luck noise. They feed
 * the Dixon-Coles goals model (λ = attack × opp. defence × home_adv ×
 * league_avg); the low-score correction itself lives in predict.py.
 *
 * Referee profiles: plain per-match averages over officiated matches that
 * have team stats (both teams' cards/fouls summed per match).
 */
class RecomputeProfilesService
{
    public const HALF_LIFE_MATCHES = 8;
    public const XG_BLEND_WEIGHT = 0.7;

    /** Minimum home and away samples before a home-advantage factor is stored. */
    private const MIN_VENUE_MATCHES = 2;

    /**
     * @return array{team_profiles: int, referees_updated: int}
     */
    public function run(): array
    {
        $summary = [
            'team_profiles' => $this->recomputeTeamProfiles(),
            'referees_updated' => $this->recomputeRefereeProfiles(),
        ];

        Log::info('Profile recomputation finished', $summary);

        return $summary;
    }

    private function recomputeTeamProfiles(): int
    {
        $rows = MatchStat::query()
            ->join('fixtures', 'fixtures.id', '=', 'match_stats.fixture_id')
            ->where('fixtures.status', Fixture::STATUS_FINISHED)
            ->orderBy('fixtures.kickoff_utc')
            ->get([
                'match_stats.*',
                'fixtures.league_id as league_id',
                'fixtures.season as season',
                'fixtures.kickoff_utc as fixture_kickoff',
                'fixtures.home_goals as fixture_home_goals',
                'fixtures.away_goals as fixture_away_goals',
            ])
            ->map(fn (MatchStat $row) => $this->deriveRow($row));

        $leagueBlendTotals = $this->leagueBlendTotals($rows);

        $written = 0;

        foreach ($rows->groupBy('team_id') as $teamId => $teamRows) {
            /** @var Collection $teamRows */
            foreach ($teamRows->pluck('season')->unique() as $season) {
                $window = $teamRows
                    ->whereIn('season', [$season, $this->previousSeason($season)])
                    ->values();

                // Weight from most recent backwards: latest match k=0.
                $count = $window->count();
                $window = $window->map(function (array $row, int $index) use ($count) {
                    $row['weight'] = 0.5 ** (($count - 1 - $index) / self::HALF_LIFE_MATCHES);

                    return $row;
                });

                $leagueAvg = $this->leagueWindowAverage(
                    $leagueBlendTotals,
                    $window->first()['league_id'],
                    $season,
                );

                $attackBlend = $this->weightedAverage($window, 'blend_for');
                $defenceBlend = $this->weightedAverage($window, 'blend_against');

                TeamProfile::updateOrCreate(
                    ['team_id' => $teamId, 'season' => $season],
                    [
                        'matches_played' => $teamRows->where('season', $season)->count(),
                        'attack_strength' => $this->ratio($attackBlend, $leagueAvg),
                        'defence_strength' => $this->ratio($defenceBlend, $leagueAvg),
                        'xg_for_avg' => $this->rounded($this->weightedAverage($window, 'xg')),
                        'xg_against_avg' => $this->rounded($this->weightedAverage($window, 'xga')),
                        'corners_for_avg' => $this->rounded($this->weightedAverage($window, 'corners_for')),
                        'corners_against_avg' => $this->rounded($this->weightedAverage($window, 'corners_against')),
                        'crosses_avg' => $this->rounded($this->weightedAverage($window, 'crosses')),
                        'cards_avg' => $this->rounded($this->weightedAverage($window, 'cards')),
                        'fouls_committed_avg' => $this->rounded($this->weightedAverage($window, 'fouls_committed')),
                        'fouls_drawn_avg' => $this->rounded($this->weightedAverage($window, 'fouls_drawn')),
                        'sot_for_avg' => $this->rounded($this->weightedAverage($window, 'shots_on_target')),
                        'sot_against_avg' => $this->rounded($this->weightedAverage($window, 'shots_on_target_against')),
                        'home_advantage_factor' => $this->homeAdvantage($window),
                    ],
                );

                $written++;
            }
        }

        return $written;
    }

    /**
     * Flatten a joined match_stats row into the metrics the profile needs.
     */
    private function deriveRow(MatchStat $row): array
    {
        $goalsFor = $row->goals ?? ($row->is_home ? $row->fixture_home_goals : $row->fixture_away_goals);
        $goalsAgainst = $row->is_home ? $row->fixture_away_goals : $row->fixture_home_goals;

        $cards = ($row->yellows === null && $row->reds === null)
            ? null
            : ($row->yellows ?? 0) + ($row->reds ?? 0);

        return [
            'team_id' => $row->team_id,
            'league_id' => $row->league_id,
            'season' => $row->season,
            'is_home' => (bool) $row->is_home,
            'blend_for' => $this->blend($row->xg, $goalsFor),
            'blend_against' => $this->blend($row->xga, $goalsAgainst),
            'xg' => $row->xg,
            'xga' => $row->xga,
            'corners_for' => $row->corners_for,
            'corners_against' => $row->corners_against,
            'crosses' => $row->crosses,
            'cards' => $cards,
            'fouls_committed' => $row->fouls_committed,
            'fouls_drawn' => $row->fouls_drawn,
            'shots_on_target' => $row->shots_on_target,
            'shots_on_target_against' => $row->shots_on_target_against,
        ];
    }

    /**
     * 70% xG / 30% goals; falls back to whichever signal exists.
     */
    private function blend(?float $xg, ?float $goals): ?float
    {
        if ($xg !== null && $goals !== null) {
            return self::XG_BLEND_WEIGHT * $xg + (1 - self::XG_BLEND_WEIGHT) * $goals;
        }

        return $xg ?? $goals;
    }

    /**
     * Per (league, season): sum/count of blend_for, so any two-season window
     * average can be assembled without rescanning rows.
     *
     * @return array<int, array<string, array{sum: float, count: int}>>
     */
    private function leagueBlendTotals(Collection $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            if ($row['blend_for'] === null) {
                continue;
            }
            $bucket = &$totals[$row['league_id']][$row['season']];
            $bucket['sum'] = ($bucket['sum'] ?? 0) + $row['blend_for'];
            $bucket['count'] = ($bucket['count'] ?? 0) + 1;
        }

        return $totals;
    }

    private function leagueWindowAverage(array $totals, int $leagueId, string $season): ?float
    {
        $sum = 0.0;
        $count = 0;

        foreach ([$season, $this->previousSeason($season)] as $windowSeason) {
            $bucket = $totals[$leagueId][$windowSeason] ?? null;
            if ($bucket !== null) {
                $sum += $bucket['sum'];
                $count += $bucket['count'];
            }
        }

        return $count > 0 ? $sum / $count : null;
    }

    private function weightedAverage(Collection $window, string $key): ?float
    {
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($window as $row) {
            if ($row[$key] === null) {
                continue;
            }
            $weightedSum += $row['weight'] * $row[$key];
            $weightTotal += $row['weight'];
        }

        return $weightTotal > 0 ? $weightedSum / $weightTotal : null;
    }

    private function homeAdvantage(Collection $window): ?float
    {
        $home = $window->where('is_home', true);
        $away = $window->where('is_home', false);

        if ($home->count() < self::MIN_VENUE_MATCHES || $away->count() < self::MIN_VENUE_MATCHES) {
            return null;
        }

        $homeBlend = $this->weightedAverage($home, 'blend_for');
        $awayBlend = $this->weightedAverage($away, 'blend_for');

        if ($homeBlend === null || $awayBlend === null || $awayBlend <= 0) {
            return null;
        }

        return round($homeBlend / $awayBlend, 4);
    }

    private function ratio(?float $value, ?float $benchmark): ?float
    {
        if ($value === null || $benchmark === null || $benchmark <= 0) {
            return null;
        }

        return round($value / $benchmark, 4);
    }

    private function rounded(?float $value): ?float
    {
        return $value === null ? null : round($value, 3);
    }

    /**
     * "2025-2026" -> "2024-2025".
     */
    private function previousSeason(string $season): string
    {
        if (preg_match('/^(\d{4})-(\d{4})$/', $season, $matches) !== 1) {
            return $season;
        }

        return ((int) $matches[1] - 1).'-'.((int) $matches[2] - 1);
    }

    private function recomputeRefereeProfiles(): int
    {
        $aggregates = Fixture::query()
            ->where('fixtures.status', Fixture::STATUS_FINISHED)
            ->whereNotNull('fixtures.referee_id')
            ->join('match_stats', 'match_stats.fixture_id', '=', 'fixtures.id')
            ->groupBy('fixtures.referee_id')
            ->select('fixtures.referee_id')
            ->selectRaw('COUNT(DISTINCT fixtures.id) as officiated')
            ->selectRaw('SUM(COALESCE(match_stats.yellows, 0)) as total_yellows')
            ->selectRaw('SUM(COALESCE(match_stats.reds, 0)) as total_reds')
            ->selectRaw('SUM(COALESCE(match_stats.fouls_committed, 0)) as total_fouls')
            ->get();

        foreach ($aggregates as $aggregate) {
            Referee::whereKey($aggregate->referee_id)->update([
                'matches_officiated' => $aggregate->officiated,
                'avg_yellows_per_match' => round($aggregate->total_yellows / $aggregate->officiated, 2),
                'avg_reds_per_match' => round($aggregate->total_reds / $aggregate->officiated, 2),
                'avg_fouls_per_match' => round($aggregate->total_fouls / $aggregate->officiated, 2),
            ]);
        }

        return $aggregates->count();
    }
}
