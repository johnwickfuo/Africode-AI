<?php

namespace App\Services\Predictions;

use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\Prediction;
use App\Models\PredictionMarket;
use App\Models\TeamProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Daily prediction generation (spec section 6, 06:00 job):
 *
 *  1. Export fixtures for the next 7 days with both teams' profiles, the
 *     referee profile, and league averages to predict_input.json.
 *  2. Run scripts/predict.py (pure computation, no network).
 *  3. Import the output into predictions + prediction_markets — one
 *     prediction row per fixture per run, one market row per line on the
 *     side the model favours.
 *
 * The web app only ever reads these tables; it never computes.
 */
class GeneratePredictionsService
{
    private const XG_BLEND_WEIGHT = 0.7;

    /**
     * @return array{fixtures_exported: int, predictions_created: int, market_rows: int, fixtures_skipped: int}
     */
    public function run(): array
    {
        $input = $this->buildInput();

        if ($input['fixtures'] === []) {
            Log::info('Prediction generation: no upcoming fixtures to predict.');

            return ['fixtures_exported' => 0, 'predictions_created' => 0, 'market_rows' => 0, 'fixtures_skipped' => 0];
        }

        $inputPath = config('africode.predict.input_path');
        $outputPath = config('africode.predict.output_path');
        File::ensureDirectoryExists(dirname($inputPath));
        file_put_contents($inputPath, json_encode($input, JSON_UNESCAPED_UNICODE));

        $process = new Process([
            config('africode.python_bin'),
            config('africode.predict.script_path'),
            '--input', $inputPath,
            '--output', $outputPath,
        ]);
        $process->setTimeout((int) config('africode.predict.timeout_seconds'));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'predict.py exited with code %d: %s',
                $process->getExitCode(),
                Str::limit(trim($process->getErrorOutput()) ?: trim($process->getOutput()), 1500),
            ));
        }

        $summary = $this->import($outputPath) + ['fixtures_exported' => count($input['fixtures'])];

        Log::info('Prediction generation finished', $summary);

        return $summary;
    }

    private function buildInput(): array
    {
        $fixtures = Fixture::upcoming()
            ->where('kickoff_utc', '<=', now('UTC')->addDays((int) config('africode.predict.days_ahead')))
            ->with(['homeTeam:id,name', 'awayTeam:id,name', 'referee'])
            ->get();

        return [
            'generated_at' => now('UTC')->toIso8601String(),
            'config' => [
                'best_bet_min_prob' => config('africode.best_bet.min_prob'),
                'best_bet_max_prob' => config('africode.best_bet.max_prob'),
            ],
            'league_averages' => $this->leagueAverages(),
            'fixtures' => $fixtures->map(fn (Fixture $fixture) => [
                'fixture_id' => $fixture->id,
                'league_id' => $fixture->league_id,
                'kickoff_utc' => $fixture->kickoff_utc->toIso8601String(),
                'is_derby' => $fixture->is_derby,
                'referee' => $fixture->referee?->only([
                    'name', 'matches_officiated', 'avg_yellows_per_match',
                    'avg_reds_per_match', 'avg_fouls_per_match',
                ]),
                'home' => $this->teamPayload($fixture, $fixture->home_team_id, $fixture->homeTeam->name),
                'away' => $this->teamPayload($fixture, $fixture->away_team_id, $fixture->awayTeam->name),
            ])->all(),
        ];
    }

    private function teamPayload(Fixture $fixture, int $teamId, string $name): array
    {
        $profile = TeamProfile::where('team_id', $teamId)->where('season', $fixture->season)->first()
            ?? TeamProfile::where('team_id', $teamId)->orderByDesc('season')->first();

        return [
            'team_id' => $teamId,
            'name' => $name,
            'matches_played' => $profile?->matches_played ?? 0,
            'attack_strength' => $profile?->attack_strength,
            'defence_strength' => $profile?->defence_strength,
            'home_advantage_factor' => $profile?->home_advantage_factor,
            'corners_for_avg' => $profile?->corners_for_avg,
            'corners_against_avg' => $profile?->corners_against_avg,
            'crosses_avg' => $profile?->crosses_avg,
            'cards_avg' => $profile?->cards_avg,
            'fouls_committed_avg' => $profile?->fouls_committed_avg,
            'sot_for_avg' => $profile?->sot_for_avg,
            'sot_against_avg' => $profile?->sot_against_avg,
        ];
    }

    /**
     * Per-league history aggregates keyed by league id (string keys for JSON):
     * goal blend per team-match, corners/cards per-match mean and variance
     * (negative binomial dispersion inputs), crossing and foul norms.
     */
    private function leagueAverages(): array
    {
        $rows = MatchStat::query()
            ->join('fixtures', 'fixtures.id', '=', 'match_stats.fixture_id')
            ->where('fixtures.status', Fixture::STATUS_FINISHED)
            ->get([
                'match_stats.*',
                'fixtures.league_id as league_id',
                'fixtures.home_goals as fixture_home_goals',
                'fixtures.away_goals as fixture_away_goals',
            ]);

        $averages = [];

        foreach ($rows->groupBy('league_id') as $leagueId => $leagueRows) {
            $blends = [];
            $crosses = [];
            $fouls = [];

            foreach ($leagueRows as $row) {
                $goals = $row->goals ?? ($row->is_home ? $row->fixture_home_goals : $row->fixture_away_goals);
                $blend = $this->blend($row->xg, $goals !== null ? (float) $goals : null);
                if ($blend !== null) {
                    $blends[] = $blend;
                }
                if ($row->crosses !== null) {
                    $crosses[] = $row->crosses;
                }
                if ($row->fouls_committed !== null) {
                    $fouls[] = $row->fouls_committed;
                }
            }

            [$cornersMean, $cornersVar] = $this->momentsOfFixtureTotals(
                $leagueRows,
                fn (MatchStat $row) => $row->corners_for !== null && $row->corners_against !== null
                    ? $row->corners_for + $row->corners_against
                    : null,
            );

            [$cardsMean, $cardsVar] = $this->momentsOfFixtureTotals(
                $leagueRows,
                function (Collection $fixtureRows) {
                    if ($fixtureRows->count() < 2) {
                        return null;
                    }
                    $withCards = $fixtureRows->filter(fn (MatchStat $r) => $r->yellows !== null || $r->reds !== null);

                    return $withCards->count() === 2
                        ? $withCards->sum(fn (MatchStat $r) => ($r->yellows ?? 0) + ($r->reds ?? 0))
                        : null;
                },
                perFixture: true,
            );

            $averages[(string) $leagueId] = [
                'goals_blend_avg' => $this->mean($blends),
                'corners_total_mean' => $cornersMean,
                'corners_total_var' => $cornersVar,
                'cards_total_mean' => $cardsMean,
                'cards_total_var' => $cardsVar,
                'crosses_avg' => $this->mean($crosses),
                'fouls_avg' => $this->mean($fouls),
            ];
        }

        return $averages;
    }

    /**
     * Mean and sample variance of a per-fixture total. Row mode derives the
     * total from a single team row (for + against); per-fixture mode gets the
     * fixture's rows and returns the total or null.
     */
    private function momentsOfFixtureTotals(Collection $leagueRows, callable $total, bool $perFixture = false): array
    {
        $totals = [];

        foreach ($leagueRows->groupBy('fixture_id') as $fixtureRows) {
            if ($perFixture) {
                $value = $total($fixtureRows);
            } else {
                $value = $fixtureRows->map($total)->filter(fn ($v) => $v !== null)->first();
            }
            if ($value !== null) {
                $totals[] = (float) $value;
            }
        }

        $mean = $this->mean($totals);
        if ($mean === null || count($totals) < 2) {
            return [$mean, null];
        }

        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $totals)) / (count($totals) - 1);

        return [$mean, $variance];
    }

    private function blend(?float $xg, ?float $goals): ?float
    {
        if ($xg !== null && $goals !== null) {
            return self::XG_BLEND_WEIGHT * $xg + (1 - self::XG_BLEND_WEIGHT) * $goals;
        }

        return $xg ?? $goals;
    }

    private function mean(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * @return array{predictions_created: int, market_rows: int, fixtures_skipped: int}
     */
    private function import(string $outputPath): array
    {
        $payload = json_decode((string) file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);
        $modelVersion = $payload['model_version'] ?? 'unknown';

        $created = 0;
        $marketRows = 0;
        $now = now();

        foreach ($payload['predictions'] ?? [] as $entry) {
            $best = $entry['best_bet'];

            $prediction = Prediction::create([
                'fixture_id' => $entry['fixture_id'],
                'generated_at' => $now,
                'model_version' => $modelVersion,
                'best_bet_market' => $best['market'],
                'best_bet_line' => $best['line'],
                'best_bet_direction' => $best['direction'],
                'best_bet_probability' => $best['probability'],
                'headline_text' => $best['headline'],
            ]);

            PredictionMarket::insert(array_map(fn (array $row) => [
                'prediction_id' => $prediction->id,
                'market' => $row['market'],
                'line' => $row['line'],
                'direction' => $row['direction'],
                'probability' => $row['probability'],
                'confidence_margin' => $row['confidence_margin'],
                'outcome' => PredictionMarket::OUTCOME_PENDING,
                'created_at' => $now,
                'updated_at' => $now,
            ], $entry['markets']));

            $created++;
            $marketRows += count($entry['markets']);
        }

        foreach ($payload['skipped'] ?? [] as $skipped) {
            Log::warning('Prediction generation: fixture skipped', $skipped);
        }

        return [
            'predictions_created' => $created,
            'market_rows' => $marketRows,
            'fixtures_skipped' => count($payload['skipped'] ?? []),
        ];
    }
}
