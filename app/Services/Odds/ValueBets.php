<?php

namespace App\Services\Odds;

use App\Models\FixtureOdds;
use App\Models\Prediction;

/**
 * Compares the champion model's probabilities against the bookmaker
 * market. Implied probabilities are overround-stripped (raw 1/odds sum
 * past 100%; each is divided by that sum), so an edge is a genuine
 * disagreement with the market, not a share of the bookmaker's margin.
 */
class ValueBets
{
    /**
     * Value comparison rows for a prediction + odds pair: the model's 1X2
     * pick and (when priced) the 2.5-goals line, each with the market's
     * implied probability and the model's edge over it.
     *
     * @return list<array{market: string, pick: string, odds: float, model_probability: float, implied_probability: float, edge: float, is_value: bool}>
     */
    public function compare(?Prediction $prediction, ?FixtureOdds $odds): array
    {
        if ($prediction === null || $odds === null) {
            return [];
        }

        $threshold = (float) config('africode.odds.value_edge_threshold', 0.05);
        $markets = $prediction->relationLoaded('markets') ? $prediction->markets : $prediction->markets()->get();
        $rows = [];

        // 1X2: the model's result pick vs its market price.
        $result = $markets->firstWhere('market', 'result');
        if ($result !== null && $odds->home_odds && $odds->draw_odds && $odds->away_odds) {
            $implied = $this->stripOverround([
                'home' => $odds->home_odds, 'draw' => $odds->draw_odds, 'away' => $odds->away_odds,
            ]);
            $rows[] = $this->row(
                'result',
                $result->direction,
                [
                    'home' => $odds->home_odds, 'draw' => $odds->draw_odds, 'away' => $odds->away_odds,
                ][$result->direction],
                (float) $result->probability,
                $implied[$result->direction],
                $threshold,
            );
        }

        // Over/under 2.5 goals: the model's side of the priced line.
        $goals25 = $markets->first(fn ($row) => $row->market === 'goals' && (float) $row->line === 2.5);
        if ($goals25 !== null && $odds->over25_odds && $odds->under25_odds) {
            $implied = $this->stripOverround(['over' => $odds->over25_odds, 'under' => $odds->under25_odds]);
            $rows[] = $this->row(
                'goals_2.5',
                $goals25->direction,
                $goals25->direction === 'over' ? $odds->over25_odds : $odds->under25_odds,
                (float) $goals25->probability,
                $implied[$goals25->direction],
                $threshold,
            );
        }

        return $rows;
    }

    /**
     * The best value edge across compared markets, or null — the one-number
     * summary fixture cards badge on.
     */
    public function bestEdge(?Prediction $prediction, ?FixtureOdds $odds): ?array
    {
        $rows = array_filter($this->compare($prediction, $odds), fn (array $row) => $row['is_value']);
        if ($rows === []) {
            return null;
        }

        usort($rows, fn (array $a, array $b) => $b['edge'] <=> $a['edge']);

        return $rows[0];
    }

    /**
     * @param  array<string, float>  $odds
     * @return array<string, float>
     */
    private function stripOverround(array $odds): array
    {
        $raw = array_map(fn (float $price) => 1 / $price, $odds);
        $sum = array_sum($raw);

        return array_map(fn (float $probability) => $probability / $sum, $raw);
    }

    private function row(string $market, string $pick, float $price, float $model, float $implied, float $threshold): array
    {
        $edge = round($model - $implied, 4);

        return [
            'market' => $market,
            'pick' => $pick,
            'odds' => round($price, 2),
            'model_probability' => round($model, 4),
            'implied_probability' => round($implied, 4),
            'edge' => $edge,
            'is_value' => $edge >= $threshold,
        ];
    }
}
