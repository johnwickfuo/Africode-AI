<?php

namespace App\Services\Accas;

use App\Models\Accumulator;
use App\Models\AccumulatorLeg;
use App\Models\Fixture;
use App\Models\Prediction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds the daily accumulator set from the latest predictions, one acca
 * per target-odds tier (3x ... 10000x), using model fair odds (1/p).
 *
 * Separation rules (within a generation run):
 *  - The conflict unit is (fixture, market): once any acca carries a pick
 *    from a fixture's market, no other acca may use ANY pick from that same
 *    fixture+market — same line, the opposite direction, or a nearby line
 *    are all the same call. Other markets of that fixture stay available.
 *  - Within a single acca, at most one leg per fixture (same-match legs are
 *    correlated, which would overstate the combined odds).
 *
 * Selection: fewest legs, then minimal overshoot. With fair odds every
 * combo landing exactly on the target has win probability 1/target, so
 * overshoot is the only thing worth minimizing — greedy-fill with the
 * longest eligible legs, then swap the final leg for the shortest one that
 * still clears the target.
 *
 * Tiers the remaining pool cannot reach are skipped (shown as unavailable)
 * rather than built with weakened rules.
 */
class AccumulatorBuilderService
{
    /**
     * @return array{built: list<int>, skipped: list<int>, legs: int}
     */
    public function run(): array
    {
        $pool = $this->legPool();
        $generatedAt = now();

        $summary = ['built' => [], 'skipped' => [], 'legs' => 0];
        $usedFixtureMarkets = [];

        foreach (config('africode.accas.tiers') as $target) {
            $legs = $this->buildTier((int) $target, $pool, $usedFixtureMarkets);

            if ($legs === null) {
                $summary['skipped'][] = (int) $target;

                continue;
            }

            DB::transaction(function () use ($legs, $target, $generatedAt) {
                $combinedOdds = array_product(array_column($legs, 'odds'));

                $accumulator = Accumulator::create([
                    'generated_at' => $generatedAt,
                    'target_odds' => $target,
                    'combined_odds' => round($combinedOdds, 2),
                    'combined_probability' => round(1 / $combinedOdds, 8),
                    'legs_count' => count($legs),
                ]);

                foreach ($legs as $leg) {
                    AccumulatorLeg::create(['accumulator_id' => $accumulator->id] + collect($leg)->only([
                        'prediction_market_id', 'fixture_id', 'market', 'line', 'direction', 'probability', 'odds',
                    ])->all());
                }
            });

            foreach ($legs as $leg) {
                $usedFixtureMarkets[$leg['fixture_id'].'|'.$leg['market']] = true;
            }
            $summary['built'][] = (int) $target;
            $summary['legs'] += count($legs);
        }

        Log::info('Accumulator generation finished', $summary);

        return $summary;
    }

    /**
     * Eligible legs: every market row of each upcoming fixture's latest
     * prediction, inside the configured probability band.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function legPool(): Collection
    {
        $minProb = (float) config('africode.accas.leg_min_prob');
        $maxProb = (float) config('africode.accas.leg_max_prob');

        $fixtures = Fixture::upcoming()
            ->where('kickoff_utc', '<=', now('UTC')->addDays((int) config('africode.predict.days_ahead')))
            // No limit() here: an eager-load limit applies to the whole
            // relation query, not per fixture. first() below picks the
            // newest prediction per fixture from the ordered collection.
            ->with(['predictions' => fn ($query) => $query->champion()->orderByDesc('generated_at')])
            ->get();

        return $fixtures
            ->flatMap(function (Fixture $fixture) use ($minProb, $maxProb) {
                /** @var Prediction|null $prediction */
                $prediction = $fixture->predictions->first();
                if ($prediction === null) {
                    return [];
                }

                return $prediction->markets()
                    ->whereBetween('probability', [$minProb, $maxProb])
                    ->get()
                    ->map(fn ($market) => [
                        'prediction_market_id' => $market->id,
                        'fixture_id' => $fixture->id,
                        'market' => $market->market,
                        'line' => $market->line !== null ? (float) $market->line : null,
                        'direction' => $market->direction,
                        'probability' => (float) $market->probability,
                        'odds' => round(1 / (float) $market->probability, 3),
                    ]);
            })
            // Longest odds first; deterministic tie-breaks.
            ->sortBy([
                ['probability', 'asc'],
                ['fixture_id', 'asc'],
                ['market', 'asc'],
                ['line', 'asc'],
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $pool
     * @param  array<string, true>  $usedFixtureMarkets
     * @return list<array<string, mixed>>|null null when the tier is unreachable
     */
    private function buildTier(int $target, Collection $pool, array $usedFixtureMarkets): ?array
    {
        $available = $pool->filter(
            fn (array $leg) => ! isset($usedFixtureMarkets[$leg['fixture_id'].'|'.$leg['market']]),
        )->values();

        // Greedy fill: longest eligible leg per unused fixture until the
        // product clears the target.
        $legs = [];
        $fixturesInAcca = [];
        $product = 1.0;

        foreach ($available as $leg) {
            if (isset($fixturesInAcca[$leg['fixture_id']])) {
                continue;
            }
            $legs[] = $leg;
            $fixturesInAcca[$leg['fixture_id']] = true;
            $product *= $leg['odds'];

            if ($product >= $target) {
                break;
            }
        }

        if ($product < $target) {
            return null;
        }

        // Overshoot reduction: swap the last leg for the shortest available
        // leg that still clears the target.
        $last = array_pop($legs);
        unset($fixturesInAcca[$last['fixture_id']]);
        $productWithoutLast = $product / $last['odds'];
        $neededOdds = $target / $productWithoutLast;

        $replacement = $available
            ->filter(fn (array $leg) => ! isset($fixturesInAcca[$leg['fixture_id']])
                && $leg['odds'] >= $neededOdds
                // Not already used as an earlier leg of this acca.
                && ! collect($legs)->contains('prediction_market_id', $leg['prediction_market_id']))
            ->sortBy([['probability', 'desc'], ['fixture_id', 'asc'], ['market', 'asc'], ['line', 'asc']])
            ->first();

        $legs[] = $replacement ?? $last;

        return $legs;
    }
}
