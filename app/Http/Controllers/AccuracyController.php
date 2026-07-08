<?php

namespace App\Http\Controllers;

use App\Models\Prediction;
use App\Models\PredictionMarket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AccuracyController extends Controller
{
    /**
     * The truth-teller page (spec 8.3): per-market hit rates over 30/90/all
     * days, headline Best Bet win rate, a calibration table, and
     * model-version comparison — all computed from settled market rows.
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
        ]);
    }

    private function settledQuery(?int $days): Builder
    {
        return PredictionMarket::query()
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
            'total_settled' => $rows->count(),
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
