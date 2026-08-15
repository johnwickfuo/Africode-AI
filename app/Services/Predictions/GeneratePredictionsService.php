<?php

namespace App\Services\Predictions;

use App\Models\AccumulatorLeg;
use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\Prediction;
use App\Models\PredictionMarket;
use App\Models\TeamProfile;
use App\Support\ProcessOutput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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
                ProcessOutput::tail($process),
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
            ->with(['league:id,code', 'homeTeam:id,name', 'awayTeam:id,name', 'referee'])
            ->get();

        return [
            'generated_at' => now('UTC')->toIso8601String(),
            'config' => [
                'best_bet_min_prob' => config('africode.best_bet.min_prob'),
                'best_bet_max_prob' => config('africode.best_bet.max_prob'),
                'bettable_markets' => config('africode.markets.bettable'),
                'min_headline_line' => config('africode.markets.min_headline_line'),
                'cards_leagues' => config('africode.markets.cards_leagues'),
                'min_headline_odds' => config('africode.markets.min_headline_odds'),
                // So the model can judge a pick on what it would pay, the
                // same way App\Services\Odds\MarketPricing does.
                'margins' => config('africode.odds.margin'),
                'margin_multiplier' => config('africode.odds.margin_multiplier'),
            ],
            'league_averages' => $this->leagueAverages(),
            'training' => $this->challengerTrainingRows(),
            'fixtures' => $fixtures->map(fn (Fixture $fixture) => [
                'fixture_id' => $fixture->id,
                'league_id' => $fixture->league_id,
                'league_code' => $fixture->league->code,
                'kickoff_utc' => $fixture->kickoff_utc->toIso8601String(),
                'is_derby' => $fixture->is_derby,
                // Pre-match form for the challenger, from the same history
                // walk that produced the training rows.
                'form' => array_combine(
                    ['home_ppg5', 'away_ppg5', 'home_rest', 'away_rest'],
                    $this->formFeatures($fixture->home_team_id, $fixture->away_team_id, $fixture->kickoff_utc),
                ),
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

        $prior = config('africode.priors.promoted');

        // A club with no profile history at all is a promoted side: without
        // a prior it would be skipped until several matchdays in. Clubs
        // whose only history is this season blend observation with the
        // prior while matches are few; established clubs (any earlier
        // season on record) are untouched — their own past is the better
        // prior and the profile recompute already uses it.
        $isNewcomer = $profile === null
            || ($profile->season === $fixture->season
                && TeamProfile::where('team_id', $teamId)->where('season', '<', $fixture->season)->doesntExist());

        $matches = $profile?->matches_played ?? 0;
        $weight = $isNewcomer
            ? $matches / ($matches + (int) config('africode.priors.blend_matches'))
            : 1.0;

        $value = function (string $key) use ($profile, $prior, $isNewcomer, $weight) {
            $observed = $profile?->{$key};
            if (! $isNewcomer) {
                return $observed;
            }
            if ($observed === null) {
                return $prior[$key];
            }

            return round($weight * (float) $observed + (1 - $weight) * $prior[$key], 4);
        };

        return [
            'team_id' => $teamId,
            'name' => $name,
            'matches_played' => $matches,
            'attack_strength' => $value('attack_strength'),
            'defence_strength' => $value('defence_strength'),
            'home_advantage_factor' => $value('home_advantage_factor'),
            'xg_for_avg' => $value('xg_for_avg'),
            'xg_against_avg' => $value('xg_against_avg'),
            'corners_for_avg' => $value('corners_for_avg'),
            'corners_against_avg' => $value('corners_against_avg'),
            'crosses_avg' => $value('crosses_avg'),
            'cards_avg' => $value('cards_avg'),
            'fouls_committed_avg' => $value('fouls_committed_avg'),
            'sot_for_avg' => $value('sot_for_avg'),
            'sot_against_avg' => $value('sot_against_avg'),
        ];
    }

    /**
     * Training rows for the ML challenger (1X2): every finished fixture with
     * both teams' season profiles and the observed result. Features come
     * from the season-level profiles rather than pre-match snapshots — a
     * known simplification; the honest referee is the live A/B on future
     * fixtures, which both models predict blind.
     *
     * @return list<array<string, mixed>>
     */
    private function challengerTrainingRows(): array
    {
        $profiles = TeamProfile::all()->keyBy(fn (TeamProfile $p) => $p->team_id.'|'.$p->season);
        $this->formHistory = [];
        $rows = [];

        // Chronological walk so each row's form features only see matches
        // played BEFORE that fixture — no peeking at the future.
        $fixtures = Fixture::finished()
            ->whereNotNull('home_goals')
            ->orderBy('kickoff_utc')
            ->get(['id', 'season', 'home_team_id', 'away_team_id', 'home_goals', 'away_goals', 'kickoff_utc', 'is_derby']);

        foreach ($fixtures as $fixture) {
            $home = $profiles->get($fixture->home_team_id.'|'.$fixture->season);
            $away = $profiles->get($fixture->away_team_id.'|'.$fixture->season);

            if ($home !== null && $away !== null) {
                $features = [
                    ...$this->challengerFeatures($home, $away),
                    ...$this->formFeatures($fixture->home_team_id, $fixture->away_team_id, $fixture->kickoff_utc),
                    $fixture->is_derby ? 1.0 : 0.0,
                ];
                if (! in_array(null, $features, true)) {
                    $rows[] = [
                        'features' => $features,
                        'result' => $fixture->home_goals <=> $fixture->away_goals
                            ? ($fixture->home_goals > $fixture->away_goals ? 'home' : 'away')
                            : 'draw',
                    ];
                }
            }

            $this->pushFormResult($fixture);
        }

        return $rows;
    }

    /** @var array<int, list<array{kickoff: Carbon, points: int}>> */
    private array $formHistory = [];

    private function pushFormResult(Fixture $fixture): void
    {
        $homePoints = $fixture->home_goals <=> $fixture->away_goals
            ? ($fixture->home_goals > $fixture->away_goals ? 3 : 0) : 1;

        $this->formHistory[$fixture->home_team_id][] = ['kickoff' => $fixture->kickoff_utc, 'points' => $homePoints];
        $this->formHistory[$fixture->away_team_id][] = ['kickoff' => $fixture->kickoff_utc, 'points' => $homePoints === 3 ? 0 : ($homePoints === 0 ? 3 : 1)];
    }

    /**
     * [home_ppg5, away_ppg5, home_rest_days, away_rest_days] as of the
     * given kickoff. Defaults (league-typical 1.3 ppg, 7 rest days) keep
     * season openers and promoted sides usable instead of dropping them.
     *
     * @return list<float>
     */
    private function formFeatures(int $homeId, int $awayId, $kickoff): array
    {
        $features = [];
        foreach ([[$homeId, 'ppg'], [$awayId, 'ppg'], [$homeId, 'rest'], [$awayId, 'rest']] as [$teamId, $kind]) {
            $history = $this->formHistory[$teamId] ?? [];
            if ($kind === 'ppg') {
                $recent = array_slice($history, -5);
                $features[] = $recent === []
                    ? 1.3
                    : round(array_sum(array_column($recent, 'points')) / count($recent), 3);
            } else {
                $last = $history === [] ? null : end($history)['kickoff'];
                $features[] = $last === null ? 7.0 : (float) min($last->diffInDays($kickoff), 14);
            }
        }

        return $features;
    }

    /**
     * Feature vector shared by training and inference — order matters.
     *
     * @return list<float|null>
     */
    private function challengerFeatures(TeamProfile|array $home, TeamProfile|array $away): array
    {
        $get = fn ($side, string $key) => is_array($side) ? ($side[$key] ?? null) : $side->{$key};

        $features = [];
        foreach (['attack_strength', 'defence_strength', 'xg_for_avg', 'xg_against_avg', 'sot_for_avg', 'sot_against_avg'] as $key) {
            $features[] = $get($home, $key) !== null ? (float) $get($home, $key) : null;
            $features[] = $get($away, $key) !== null ? (float) $get($away, $key) : null;
        }
        $features[] = $get($home, 'home_advantage_factor') !== null ? (float) $get($home, 'home_advantage_factor') : null;

        return $features;
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
     * @return array{predictions_created: int, superseded: int, market_rows: int, fixtures_skipped: int}
     */
    /**
     * Drops the predictions this run has just replaced.
     *
     * Every run used to insert another row per fixture and leave the old
     * ones behind, so a fixture accumulated a prediction per run and only
     * the newest was ever shown. Harmless until you look at the table and
     * cannot tell which one the site is using.
     *
     * Two kinds are kept regardless: anything already scored, because the
     * accuracy record is built from settled rows, and anything an
     * accumulator leg points at — prediction_markets cascade on delete, so
     * removing one would silently tear a leg out of a live ticket.
     *
     * @param  list<int>  $fixtureIds
     */
    private function supersede(array $fixtureIds, Carbon $generatedAt): int
    {
        if ($fixtureIds === []) {
            return 0;
        }

        $candidates = Prediction::whereIn('fixture_id', $fixtureIds)
            ->where('generated_at', '<', $generatedAt)
            ->pluck('id');

        if ($candidates->isEmpty()) {
            return 0;
        }

        $keep = PredictionMarket::whereIn('prediction_id', $candidates)
            ->where(fn ($query) => $query
                ->where('outcome', '!=', PredictionMarket::OUTCOME_PENDING)
                ->orWhereIn('id', AccumulatorLeg::query()->select('prediction_market_id')))
            ->distinct()
            ->pluck('prediction_id');

        return Prediction::whereIn('id', $candidates->diff($keep))->delete();
    }

    private function import(string $outputPath): array
    {
        $payload = json_decode((string) file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);
        $modelVersion = $payload['model_version'] ?? 'unknown';

        $created = 0;
        $marketRows = 0;
        $now = now();

        $batches = [
            [$payload['predictions'] ?? [], $modelVersion, false],
            [$payload['challenger_predictions'] ?? [], $payload['challenger_model_version'] ?? 'challenger', true],
        ];

        foreach ($batches as [$entries, $version, $isChallenger]) {
            foreach ($entries as $entry) {
                $best = $entry['best_bet'];

                $prediction = Prediction::create([
                    'fixture_id' => $entry['fixture_id'],
                    'generated_at' => $now,
                    'model_version' => $version,
                    'is_challenger' => $isChallenger,
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

                if (! $isChallenger) {
                    $created++;
                    $marketRows += count($entry['markets']);
                }
            }
        }

        $superseded = $this->supersede(
            collect($payload['predictions'] ?? [])->pluck('fixture_id')->all(),
            $now,
        );

        foreach ($payload['skipped'] ?? [] as $skipped) {
            Log::warning('Prediction generation: fixture skipped', $skipped);
        }

        return [
            'predictions_created' => $created,
            'superseded' => $superseded,
            'market_rows' => $marketRows,
            'fixtures_skipped' => count($payload['skipped'] ?? []),
        ];
    }
}
