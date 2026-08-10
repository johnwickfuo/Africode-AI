<?php

namespace App\Http\Controllers;

use App\Models\Accumulator;
use App\Models\Prediction;
use App\Models\PredictionMarket;
use App\Support\AccumulatorPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AccuracyController extends Controller
{
    /** How many finished tickets to keep on the page. */
    private const FINISHED_ACCAS = 12;

    /**
     * The truth-teller page (spec 8.3): per-market hit rates over 30/90/all
     * days, headline Best Bet win rate, a calibration table, and
     * model-version comparison — all computed from settled market rows.
     *
     * Also where accumulators end up once their last match has kicked off,
     * so a ticket that has run can still be read back in full.
     */
    public function __invoke(): Response
    {
        $windows = [];
        foreach (['30' => 30, '90' => 90, 'all' => null] as $key => $days) {
            $windows[$key] = $this->windowStats($days);
        }

        return Inertia::render('Accuracy', [
            'windows' => $windows,
            'model_versions' => $this->modelVersionComparison(),
            'result_models' => $this->resultModelComparison(),
            'accumulators' => $this->finishedAccumulators(),
        ]);
    }

    /**
     * The most recent tickets whose matches have all kicked off, newest
     * first. Settlement runs overnight, so the newest of these often still
     * read as pending — that is the honest state, not a gap.
     *
     * @return list<array<string, mixed>>
     */
    private function finishedAccumulators(): array
    {
        return Accumulator::query()
            ->started()
            ->with(AccumulatorPresenter::relations())
            ->orderByDesc('generated_at')
            ->orderBy('family')
            ->orderBy('max_leg_odds')
            ->orderBy('target_odds')
            ->limit(self::FINISHED_ACCAS)
            ->get()
            ->map(fn (Accumulator $accumulator) => AccumulatorPresenter::present($accumulator))
            ->all();
    }

    /**
     * Champion vs challenger on the one market they both predict (1X2):
     * settled result picks grouped by model, like-for-like.
     *
     * @return list<array{version: string, challenger: bool, total: int, hits: int, hit_rate: float, avg_probability: float}>
     */
    private function resultModelComparison(): array
    {
        return PredictionMarket::query()
            ->join('predictions', 'predictions.id', '=', 'prediction_markets.prediction_id')
            ->where('prediction_markets.market', 'result')
            ->whereIn('prediction_markets.outcome', [PredictionMarket::OUTCOME_WON, PredictionMarket::OUTCOME_LOST])
            ->groupBy('predictions.model_version', 'predictions.is_challenger')
            ->selectRaw('predictions.model_version as version, predictions.is_challenger as challenger')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN prediction_markets.outcome = 'won' THEN 1 ELSE 0 END) as hits")
            ->selectRaw('AVG(prediction_markets.probability) as avg_probability')
            // (p - won)^2 written as a product — POWER() is missing from
            // some SQLite builds the test suite runs on.
            ->selectRaw("AVG((prediction_markets.probability - CASE WHEN prediction_markets.outcome = 'won' THEN 1 ELSE 0 END) * (prediction_markets.probability - CASE WHEN prediction_markets.outcome = 'won' THEN 1 ELSE 0 END)) as brier")
            ->orderBy('challenger')
            ->get()
            ->map(fn ($row) => [
                'version' => $row->version,
                'challenger' => (bool) $row->challenger,
                'total' => (int) $row->total,
                'hits' => (int) $row->hits,
                'hit_rate' => round($row->hits / $row->total, 4),
                'avg_probability' => round((float) $row->avg_probability, 4),
                'brier' => round((float) $row->brier, 4),
            ])
            ->all();
    }

    private function settledQuery(?int $days): Builder
    {
        // Challenger rows stay out of the site-facing stats; they appear
        // only in the model-vs-model tables below.
        return PredictionMarket::query()
            ->whereHas('prediction', fn ($query) => $query->where('is_challenger', false))
            ->whereIn('outcome', [PredictionMarket::OUTCOME_WON, PredictionMarket::OUTCOME_LOST])
            ->when($days !== null, fn ($query) => $query->where('settled_at', '>=', now()->subDays($days)));
    }

    private function windowStats(?int $days): array
    {
        $rows = $this->settledQuery($days)->get(['market', 'probability', 'outcome']);

        return [
            'markets' => $this->perMarket($rows),
            'best_bets' => $this->bestBetStats($days),
            'calibration' => $this->calibration($rows),
            'scores' => $this->properScores($rows),
            'total_settled' => $rows->count(),
        ];
    }

    /**
     * Proper scoring rules over settled picks, treating each stored row as
     * a binary event (the pick won or lost) at its claimed probability.
     * Hit rate rewards cowardice — high-probability picks only; these
     * punish overconfidence symmetrically. Reference points: an oracle
     * scores 0, a coin-flipper claiming 50% scores Brier 0.25 / log-loss
     * 0.693. Lower is better.
     *
     * @return array{brier: ?float, log_loss: ?float}
     */
    private function properScores(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['brier' => null, 'log_loss' => null];
        }

        $brier = 0.0;
        $logLoss = 0.0;
        foreach ($rows as $row) {
            $won = $row->outcome === PredictionMarket::OUTCOME_WON ? 1.0 : 0.0;
            $p = min(max((float) $row->probability, 1e-6), 1 - 1e-6);
            $brier += ($p - $won) ** 2;
            $logLoss += -($won * log($p) + (1 - $won) * log(1 - $p));
        }

        return [
            'brier' => round($brier / $rows->count(), 4),
            'log_loss' => round($logLoss / $rows->count(), 4),
        ];
    }

    private function perMarket(Collection $rows): array
    {
        return $rows->groupBy('market')
            ->map(function (Collection $marketRows, string $market) {
                $hits = $marketRows->where('outcome', PredictionMarket::OUTCOME_WON)->count();
                $total = $marketRows->count();

                return [
                    'market' => $market,
                    'total' => $total,
                    'hits' => $hits,
                    'hit_rate' => round($hits / $total, 4),
                    'avg_probability' => round($marketRows->avg('probability'), 4),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Win rate of the headline Best Bets specifically: each prediction joined
     * to its own market row for the chosen market/line/direction.
     */
    private function bestBetStats(?int $days): array
    {
        $rows = Prediction::query()
            ->join('prediction_markets as pm', function ($join) {
                $join->on('pm.prediction_id', '=', 'predictions.id')
                    ->on('pm.market', '=', 'predictions.best_bet_market')
                    ->on('pm.direction', '=', 'predictions.best_bet_direction')
                    ->whereRaw('COALESCE(pm.line, -1) = COALESCE(predictions.best_bet_line, -1)');
            })
            ->where('predictions.is_challenger', false)
            ->whereIn('pm.outcome', [PredictionMarket::OUTCOME_WON, PredictionMarket::OUTCOME_LOST])
            ->when($days !== null, fn ($query) => $query->where('pm.settled_at', '>=', now()->subDays($days)))
            ->get(['pm.outcome', 'pm.probability']);

        $total = $rows->count();
        $hits = $rows->where('outcome', PredictionMarket::OUTCOME_WON)->count();

        return [
            'total' => $total,
            'hits' => $hits,
            'hit_rate' => $total > 0 ? round($hits / $total, 4) : null,
            'avg_probability' => $total > 0 ? round($rows->avg('probability'), 4) : null,
        ];
    }

    /**
     * Predicted-probability buckets (width 0.05 from 0.5 up) vs actual hit
     * rate — the calibration gap is the model's honesty metric.
     */
    private function calibration(Collection $rows): array
    {
        // Bucket keys must be strings: PHP truncates float array keys to int.
        return $rows->groupBy(fn ($row) => sprintf('%.2f', min(floor($row->probability * 20) / 20, 0.95)))
            ->map(function (Collection $bucketRows, string $bucket) {
                $from = (float) $bucket;
                $hits = $bucketRows->where('outcome', PredictionMarket::OUTCOME_WON)->count();
                $total = $bucketRows->count();
                $hitRate = $hits / $total;
                $avgProbability = $bucketRows->avg('probability');

                return [
                    'bucket' => sprintf('%.0f–%.0f%%', $from * 100, ($from + 0.05) * 100),
                    'from' => $from,
                    'total' => $total,
                    'hit_rate' => round($hitRate, 4),
                    'avg_probability' => round($avgProbability, 4),
                    'gap' => round($avgProbability - $hitRate, 4),
                ];
            })
            ->sortBy('from')
            ->values()
            ->all();
    }

    private function modelVersionComparison(): array
    {
        return PredictionMarket::query()
            ->join('predictions', 'predictions.id', '=', 'prediction_markets.prediction_id')
            ->whereIn('prediction_markets.outcome', [PredictionMarket::OUTCOME_WON, PredictionMarket::OUTCOME_LOST])
            ->groupBy('predictions.model_version')
            ->selectRaw('predictions.model_version as version')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN prediction_markets.outcome = 'won' THEN 1 ELSE 0 END) as hits")
            ->orderByDesc('version')
            ->get()
            ->map(fn ($row) => [
                'version' => $row->version,
                'total' => (int) $row->total,
                'hits' => (int) $row->hits,
                'hit_rate' => round($row->hits / $row->total, 4),
            ])
            ->all();
    }
}
