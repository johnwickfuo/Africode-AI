<?php

namespace App\Services\Odds;

use App\Models\FixtureOdds;

/**
 * What a bookmaker would actually pay for one of our picks.
 *
 * The model produces a probability, and 1/p is its FAIR price — the price
 * at which a bet is worth exactly nothing to either side. No bookmaker
 * offers that. Advertising fair odds made every ticket look longer than it
 * pays, and the error compounds: a six-leg ticket at a 7% margin per
 * selection returns about 65% of its stated total.
 *
 * Two sources, in order:
 *  - The real price, where football-data.co.uk publishes one. That covers
 *    1X2 and the 2.5 goals line, averaged across the major books.
 *  - Otherwise the fair price marked up by a per-market overround. Nobody
 *    free prices corners, cards, team goals or the other goal lines, and
 *    those are most of what a ticket is made of.
 *
 * The estimate is exactly that. A book's real margin varies by fixture,
 * league and how much it likes the bet; these figures are the going rate
 * for a mainstream book on each market, not a promise.
 */
class MarketPricing
{
    /**
     * The price for a pick, plus the fair price it came from.
     *
     * @return array{odds: float, model_odds: float, priced: bool}
     */
    public function price(
        string $market,
        ?float $line,
        string $direction,
        float $probability,
        ?FixtureOdds $odds = null,
    ): array {
        $fair = 1 / $probability;
        $real = $this->realPrice($market, $line, $direction, $odds);

        return [
            'odds' => round($real ?? ($fair / (1 + $this->margin($market))), 3),
            'model_odds' => round($fair, 3),
            'priced' => $real !== null,
        ];
    }

    /** The overround a book takes on this market. */
    public function margin(string $market): float
    {
        $margins = config('africode.odds.margin', []);
        $base = (float) ($margins[$market] ?? $margins['default'] ?? 0.09);

        return $base * (float) config('africode.odds.margin_multiplier', 1.0);
    }

    /**
     * The published price for this exact pick, when there is one. Only the
     * two markets football-data.co.uk carries can ever return a value.
     */
    private function realPrice(string $market, ?float $line, string $direction, ?FixtureOdds $odds): ?float
    {
        if ($odds === null) {
            return null;
        }

        $price = match (true) {
            $market === 'result' => match ($direction) {
                'home' => $odds->home_odds,
                'draw' => $odds->draw_odds,
                'away' => $odds->away_odds,
                default => null,
            },
            $market === 'goals' && $line !== null && abs($line - 2.5) < 0.001 => $direction === 'over'
                ? $odds->over25_odds
                : $odds->under25_odds,
            default => null,
        };

        // A price at or below evens for a pick we rate a favourite means the
        // row is junk, not a bargain; fall back to the estimate.
        return $price !== null && $price > 1.01 ? (float) $price : null;
    }
}
