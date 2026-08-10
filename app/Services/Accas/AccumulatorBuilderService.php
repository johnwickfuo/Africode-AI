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
 * Every ticket is confined to a short run of consecutive days (two by
 * default): a ticket whose legs span a fortnight cannot be settled, topped
 * up or enjoyed. Each ticket takes the EARLIEST window it can complete in,
 * so small tickets land on the next match day and the long ones drift to
 * whichever weekend is busy enough to carry them.
 *
 * Separation rules (within a generation run):
 *  - The conflict unit is (fixture, market): a pick already spent on another
 *    ticket in the same family is avoided — same line, the opposite
 *    direction, or a nearby line are all the same call. Confined to two
 *    days the pool is often too thin for that to hold, so it is a
 *    preference, not a veto: a tier that cannot be built from unspent picks
 *    alone is rebuilt allowing reuse rather than dropped. The two families
 *    keep separate ledgers.
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
 *
 * Generation is idempotent while a ticket is live: the job runs every
 * morning, but only definitions with no outstanding ticket are built. A
 * ticket therefore stands until its last match kicks off, which is what
 * makes it something anyone can actually act on.
 */
class AccumulatorBuilderService
{
    /** @var Collection<string, Accumulator> live tickets, keyed by definition */
    private Collection $live;

    /**
     * Calls already carried by a live ticket of this family, so a new
     * ticket does not land on a pick an outstanding one already holds.
     *
     * @return array<string, true>
     */
    private function spentPicks(string $family): array
    {
        $spent = [];

        foreach ($this->live->where('family', $family) as $accumulator) {
            foreach ($accumulator->legs as $leg) {
                $spent[$leg->fixture_id.'|'.$leg->market] = true;
            }
        }

        return $spent;
    }

    /**
     * @return array{built: list<int>, kept: list<int>, skipped: list<int>, legs: int, banker: array{built: list<string>, kept: list<string>, skipped: list<string>}}
     */
    public function run(): array
    {
        $pool = $this->legPool();
        $generatedAt = now();

        $summary = [
            'built' => [], 'kept' => [], 'skipped' => [], 'legs' => 0,
            'banker' => ['built' => [], 'kept' => [], 'skipped' => []],
        ];

        // A ticket published earlier that can still be backed is left alone.
        // Rebuilding it every morning would republish the same offer daily,
        // pile up identical copies in the record, and quietly change a
        // ticket somebody may already have staked.
        $this->live = Accumulator::live()->with('legs')->get()
            ->keyBy(fn (Accumulator $acca) => $acca->definitionKey());

        $classicUsed = $this->spentPicks(Accumulator::FAMILY_CLASSIC);

        foreach (config('africode.accas.tiers') as $target) {
            $key = Accumulator::keyFor(Accumulator::FAMILY_CLASSIC, null, (int) $target);
            if ($this->live->has($key)) {
                $summary['kept'][] = (int) $target;

                continue;
            }

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
        $used = $this->spentPicks(Accumulator::FAMILY_BANKER);

        foreach ($config['caps'] as $cap) {
            $cap = (float) $cap;
            $capPool = $pool->filter(fn (array $leg) => $leg['odds'] <= $cap)->values();

            foreach ($config['targets'] as $target) {
                $label = number_format($cap, 2).'/'.$target.'x';

                if ($this->live->has(Accumulator::keyFor(Accumulator::FAMILY_BANKER, $cap, (int) $target))) {
                    $summary['banker']['kept'][] = $label;

                    continue;
                }

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
        $legs = $this->buildInEarliestWindow($target, $pool, $usedFixtureMarkets, $maxLegs);

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
     * Walks the candidate windows oldest-first and returns the first
     * complete ticket. Inside a window, unspent picks are tried first so
     * tickets stay distinct where the card is deep enough; a window that
     * only works by reusing another ticket's call still beats skipping the
     * tier, which is the trade a two-day rule forces.
     *
     * @param  Collection<int, array<string, mixed>>  $pool
     * @param  array<string, true>  $usedFixtureMarkets
     * @return list<array<string, mixed>>|null
     */
    private function buildInEarliestWindow(
        int $target,
        Collection $pool,
        array $usedFixtureMarkets,
        ?int $maxLegs,
    ): ?array {
        $span = (int) config('africode.accas.window_days', 2);

        foreach ($pool->pluck('date')->unique()->sort()->values() as $start) {
            $dates = collect(range(0, $span - 1))
                ->map(fn (int $offset) => Carbon::parse($start)->addDays($offset)->toDateString())
                ->all();

            $window = $pool->filter(fn (array $leg) => in_array($leg['date'], $dates, true))->values();

            $legs = $this->buildTier($target, $window, $usedFixtureMarkets, $maxLegs)
                ?? $this->buildTier($target, $window, [], $maxLegs);

            if ($legs !== null) {
                return $legs;
            }
        }

        return null;
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
        // Windows are calendar days in the timezone the site renders, so a
        // ticket reads as "Saturday and Sunday" to the person placing it.
        $displayTz = config('africode.display_timezone');

        $fixtures = Fixture::upcoming()
            ->where('kickoff_utc', '<=', now('UTC')->addDays((int) config('africode.predict.days_ahead')))
            // No limit() here: an eager-load limit applies to the whole
            // relation query, not per fixture. first() below picks the
            // newest prediction per fixture from the ordered collection.
            ->with(['predictions' => fn ($query) => $query->champion()->orderByDesc('generated_at')])
            ->get();

        return $fixtures
            ->flatMap(function (Fixture $fixture) use ($minProb, $maxProb, $bettable, $minLine, $displayTz) {
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
                        'date' => $fixture->kickoff_utc->timezone($displayTz)->toDateString(),
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

        // Picks already on this ticket, as a lookup rather than a rescan of
        // the leg list for every candidate.
        $taken = array_flip(array_column($legs, 'prediction_market_id'));

        $replacement = $available
            ->filter(fn (array $leg) => ! isset($fixturesInAcca[$leg['fixture_id']])
                && $leg['odds'] >= $neededOdds
                && ! isset($taken[$leg['prediction_market_id']]))
            ->sortBy([['probability', 'desc'], ['fixture_id', 'asc'], ['market', 'asc'], ['line', 'asc']])
            ->first();

        $legs[] = $replacement ?? $last;

        return $legs;
    }
}
