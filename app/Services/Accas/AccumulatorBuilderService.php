<?php

namespace App\Services\Accas;

use App\Models\Accumulator;
use App\Models\AccumulatorLeg;
use App\Models\Fixture;
use App\Models\Prediction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds the daily accumulator set from the latest predictions, using model
 * fair odds (1/p), in two families:
 *
 *  - classic: one ticket per target-odds tier (3x ... 10000x), legs of any
 *    price, so a handful of long legs can carry the total.
 *  - banker: the same targets reached only with short legs. Each cap is the
 *    most a single leg may pay (1.25 = an 80%+ call), which forces long
 *    tickets — 20x from 1.25 legs takes at least 14 of them — so leg counts
 *    are capped and unreachable tiers are simply not offered.
 *
 * Separation rules (within a generation run):
 *  - The conflict unit is (fixture, market): once an acca carries a pick
 *    from a fixture's market, no other acca IN THE SAME FAMILY may use ANY
 *    pick from that same fixture+market — same line, the opposite
 *    direction, or a nearby line are all the same call. Other markets of
 *    that fixture stay available. The two families keep separate ledgers,
 *    so a banker ticket and a classic ticket may land on the same call.
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
     * @return array{built: list<int>, skipped: list<int>, legs: int, banker: array{built: list<string>, skipped: list<string>}}
     */
    public function run(): array
    {
        $pool = $this->legPool();
        $generatedAt = now();

        $summary = [
            'built' => [], 'skipped' => [], 'legs' => 0,
            'banker' => ['built' => [], 'skipped' => []],
        ];

        $classicUsed = [];
        foreach (config('africode.accas.tiers') as $target) {
            $built = $this->buildAndStore(
                target: (int) $target,
                pool: $pool,
                usedFixtureMarkets: $classicUsed,
                generatedAt: $generatedAt,
                family: Accumulator::FAMILY_CLASSIC,
                maxLegOdds: null,
                maxLegs: null,
            );

            if ($built === null) {
                $summary['skipped'][] = (int) $target;

                continue;
            }

            $summary['built'][] = (int) $target;
            $summary['legs'] += $built;
        }

        $summary = $this->buildBankerSet($pool, $generatedAt, $summary);

        Log::info('Accumulator generation finished', $summary);

        return $summary;
    }

    /**
     * The banker set: one ticket per (cap, target) pair. Each cap trims the
     * pool to legs no longer than that price before the usual greedy fill
     * runs, and a leg ceiling stops a ticket growing past what anyone would
     * actually place.
     *
     * Caps are worked tightest-first and share one ledger, so the 1.25 row
     * gets first call on the safest picks and the looser rows are built from
     * what is left. That is deliberate: it stops the three rows collapsing
     * into near-identical tickets.
     *
     * @param  Collection<int, array<string, mixed>>  $pool
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function buildBankerSet(Collection $pool, Carbon $generatedAt, array $summary): array
    {
        $config = config('africode.accas.banker');
        $maxLegs = (int) $config['max_legs'];
        $used = [];

        foreach ($config['caps'] as $cap) {
            $cap = (float) $cap;
            $capPool = $pool->filter(fn (array $leg) => $leg['odds'] <= $cap)->values();

            foreach ($config['targets'] as $target) {
                $label = number_format($cap, 2).'/'.$target.'x';

                $built = $this->buildAndStore(
                    target: (int) $target,
                    pool: $capPool,
                    usedFixtureMarkets: $used,
                    generatedAt: $generatedAt,
                    family: Accumulator::FAMILY_BANKER,
                    maxLegOdds: $cap,
                    maxLegs: $maxLegs,
                );

                if ($built === null) {
                    $summary['banker']['skipped'][] = $label;

                    continue;
                }

                $summary['banker']['built'][] = $label;
                $summary['legs'] += $built;
            }
        }

        return $summary;
    }

    /**
     * Builds one ticket and persists it, marking its picks as spent in the
     * caller's ledger.
     *
     * @param  Collection<int, array<string, mixed>>  $pool
     * @param  array<string, true>  $usedFixtureMarkets
     * @return int|null leg count, or null when the tier is unreachable
     */
    private function buildAndStore(
        int $target,
        Collection $pool,
        array &$usedFixtureMarkets,
        Carbon $generatedAt,
        string $family,
        ?float $maxLegOdds,
        ?int $maxLegs,
    ): ?int {
        $legs = $this->buildTier($target, $pool, $usedFixtureMarkets, $maxLegs);

        if ($legs === null) {
            return null;
        }

        DB::transaction(function () use ($legs, $target, $generatedAt, $family, $maxLegOdds) {
            $combinedOdds = array_product(array_column($legs, 'odds'));

            $accumulator = Accumulator::create([
                'generated_at' => $generatedAt,
                'family' => $family,
                'max_leg_odds' => $maxLegOdds,
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

        return count($legs);
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
        // A ticket is only useful if every leg can be placed, so legs come
        // from the same bettable-market list the Best Bet uses.
        $bettable = config('africode.markets.bettable');
        $minLine = config('africode.markets.min_headline_line');

        $fixtures = Fixture::upcoming()
            ->where('kickoff_utc', '<=', now('UTC')->addDays((int) config('africode.predict.days_ahead')))
            // No limit() here: an eager-load limit applies to the whole
            // relation query, not per fixture. first() below picks the
            // newest prediction per fixture from the ordered collection.
            ->with(['predictions' => fn ($query) => $query->champion()->orderByDesc('generated_at')])
            ->get();

        return $fixtures
            ->flatMap(function (Fixture $fixture) use ($minProb, $maxProb, $bettable, $minLine) {
                /** @var Prediction|null $prediction */
                $prediction = $fixture->predictions->first();
                if ($prediction === null) {
                    return [];
                }

                return $prediction->markets()
                    ->whereBetween('probability', [$minProb, $maxProb])
                    ->when($bettable !== null, fn ($query) => $query->whereIn('market', $bettable))
                    ->when($minLine !== null, fn ($query) => $query->where(
                        fn ($q) => $q->whereNull('line')->orWhere('line', '>=', $minLine),
                    ))
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
     * @param  int|null  $maxLegs  ceiling on leg count, null for unlimited
     * @return list<array<string, mixed>>|null null when the tier is unreachable
     */
    private function buildTier(int $target, Collection $pool, array $usedFixtureMarkets, ?int $maxLegs = null): ?array
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

            if ($product >= $target || ($maxLegs !== null && count($legs) >= $maxLegs)) {
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
