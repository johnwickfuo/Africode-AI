<?php

namespace Tests\Feature;

use App\Jobs\GenerateAccumulatorsJob;
use App\Models\Accumulator;
use App\Models\AccumulatorLeg;
use App\Models\Fixture;
use App\Models\PipelineRun;
use App\Models\Prediction;
use App\Models\PredictionMarket;
use App\Models\Team;
use App\Services\Accas\AccumulatorBuilderService;
use App\Services\Predictions\SettlePredictionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AccumulatorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        // Most tests here are about the classic family; banker tickets get
        // their own cases and would otherwise drain the shared fixture pool.
        config(['africode.accas.banker.caps' => []]);
    }

    /**
     * Upcoming fixture whose latest prediction carries the given markets.
     *
     * @param  array<string, float>  $markets  "market|line|direction" => probability
     */
    private function predictedFixture(array $markets, int $offset = 0): Fixture
    {
        $teams = Team::whereHas('league', fn ($q) => $q->where('code', 'PL'))
            ->orderBy('id')->get();
        $home = $teams[($offset * 2) % 20];
        $away = $teams[($offset * 2 + 1) % 20];

        $fixture = Fixture::create([
            'league_id' => $home->league_id,
            'season' => '2025-2026',
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'kickoff_utc' => now('UTC')->addDays(2)->addMinutes($offset),
            'status' => Fixture::STATUS_SCHEDULED,
        ]);

        $prediction = Prediction::create([
            'fixture_id' => $fixture->id,
            'generated_at' => now()->subHour(),
            'model_version' => 'v1.0.0',
            'best_bet_market' => 'goals',
            'best_bet_line' => 2.5,
            'best_bet_direction' => 'over',
            'best_bet_probability' => 0.7,
            'headline_text' => 'test',
        ]);

        foreach ($markets as $key => $probability) {
            [$market, $line, $direction] = explode('|', $key);
            PredictionMarket::create([
                'prediction_id' => $prediction->id,
                'market' => $market,
                'line' => $line === '' ? null : (float) $line,
                'direction' => $direction,
                'probability' => $probability,
                'confidence_margin' => abs($probability - 0.5),
            ]);
        }

        return $fixture;
    }

    /**
     * `$count` upcoming fixtures across the whole seeded club list, each
     * carrying the same markets — enough to feed a 20-leg banker ticket.
     *
     * @param  array<string, float>  $markets
     * @return list<Fixture>
     */
    private function manyPredictedFixtures(
        int $count,
        array $markets,
        int $daysAhead = 2,
        int $teamOffset = 0,
    ): array {
        $teams = Team::orderBy('id')->get();
        $fixtures = [];

        foreach (range(0, $count - 1) as $offset) {
            $home = $teams[($teamOffset + $offset) * 2];
            $away = $teams[($teamOffset + $offset) * 2 + 1];

            $fixture = Fixture::create([
                'league_id' => $home->league_id,
                'season' => '2025-2026',
                'home_team_id' => $home->id,
                'away_team_id' => $away->id,
                'kickoff_utc' => now('UTC')->addDays($daysAhead)->setTime(12, 0)->addMinutes($offset),
                'status' => Fixture::STATUS_SCHEDULED,
            ]);

            $prediction = Prediction::create([
                'fixture_id' => $fixture->id,
                'generated_at' => now()->subHour(),
                'model_version' => 'v1.1.0',
                'best_bet_market' => 'goals',
                'best_bet_line' => 2.5,
                'best_bet_direction' => 'over',
                'best_bet_probability' => 0.7,
                'headline_text' => 'test',
            ]);

            foreach ($markets as $key => $probability) {
                [$market, $line, $direction] = explode('|', $key);
                PredictionMarket::create([
                    'prediction_id' => $prediction->id,
                    'market' => $market,
                    'line' => $line === '' ? null : (float) $line,
                    'direction' => $direction,
                    'probability' => $probability,
                    'confidence_margin' => abs($probability - 0.5),
                ]);
            }

            $fixtures[] = $fixture;
        }

        return $fixtures;
    }

    public function test_accas_reach_target_and_share_no_fixture_market_call(): void
    {
        config(['africode.accas.tiers' => [3, 10]]);

        // 6 fixtures, each with a goals and a corners pick.
        foreach (range(0, 5) as $offset) {
            $this->predictedFixture([
                'goals|2.5|over' => 0.55,
                'corners|9.5|over' => 0.60,
            ], $offset);
        }

        $summary = app(AccumulatorBuilderService::class)->run();

        $this->assertSame([3, 10], $summary['built']);
        $this->assertSame([], $summary['skipped']);

        $accas = Accumulator::with('legs')->get();
        foreach ($accas as $acca) {
            $this->assertGreaterThanOrEqual($acca->target_odds, $acca->combined_odds);
            // One leg per fixture within the acca.
            $this->assertSame(
                $acca->legs->count(),
                $acca->legs->pluck('fixture_id')->unique()->count(),
            );
        }

        // No (fixture, market) call shared across accas.
        $calls = AccumulatorLeg::all()->map(fn ($leg) => $leg->fixture_id.'|'.$leg->market);
        $this->assertSame($calls->count(), $calls->unique()->count());
    }

    public function test_conflict_blocks_same_market_but_allows_other_markets_of_the_game(): void
    {
        config(['africode.accas.tiers' => [3, 3]]);

        // Two fixtures; goals AND corners picks on each, plus a second
        // goals line that must also be blocked once goals is used.
        foreach ([0, 1] as $offset) {
            $this->predictedFixture([
                'goals|2.5|over' => 0.55,
                'goals|1.5|over' => 0.60,
                'corners|9.5|over' => 0.56,
            ], $offset);
        }

        app(AccumulatorBuilderService::class)->run();

        $accas = Accumulator::with('legs')->orderBy('id')->get();
        $this->assertCount(2, $accas);

        $first = $accas[0]->legs->pluck('market')->unique();
        $second = $accas[1]->legs->pluck('market')->unique();

        // First ticket took the goals calls; the second could not touch
        // goals on those fixtures AT ANY LINE, so it holds corners only.
        $this->assertSame(['goals'], $first->values()->all());
        $this->assertSame(['corners'], $second->values()->all());
    }

    public function test_unreachable_tiers_are_skipped_not_weakened(): void
    {
        config(['africode.accas.tiers' => [3, 10000]]);

        foreach ([0, 1, 2] as $offset) {
            $this->predictedFixture(['goals|2.5|over' => 0.55], $offset);
        }

        $summary = app(AccumulatorBuilderService::class)->run();

        $this->assertSame([3], $summary['built']);
        $this->assertSame([10000], $summary['skipped']);
        $this->assertSame(1, Accumulator::count());
    }

    public function test_final_leg_is_swapped_down_to_minimize_overshoot(): void
    {
        config(['africode.accas.tiers' => [3]]);

        $this->predictedFixture(['goals|2.5|over' => 0.55], 0);   // odds 1.818
        $this->predictedFixture(['goals|2.5|over' => 0.55], 1);   // odds 1.818
        $this->predictedFixture(['goals|2.5|over' => 0.60], 2);   // odds 1.667

        app(AccumulatorBuilderService::class)->run();

        $acca = Accumulator::first();
        // Greedy pass gives 1.818 x 1.818 = 3.31; the swap replaces the
        // second leg with the 1.667 leg: 1.818 x 1.667 = 3.03.
        $this->assertEqualsWithDelta(3.03, $acca->combined_odds, 0.01);
        $this->assertSame(2, $acca->legs_count);
    }

    public function test_leg_probability_band_is_respected(): void
    {
        config(['africode.accas.tiers' => [3]]);

        // A trivial 0.96 pick and a sub-floor 0.51 pick must never be legs.
        foreach ([0, 1, 2, 3] as $offset) {
            $this->predictedFixture([
                'goals|0.5|over' => 0.96,
                'cards|5.5|under' => 0.51,
                'corners|9.5|over' => 0.60,
            ], $offset);
        }

        app(AccumulatorBuilderService::class)->run();

        $probabilities = AccumulatorLeg::pluck('probability')->map(fn ($p) => (float) $p);
        $this->assertTrue($probabilities->every(fn ($p) => $p >= 0.55 && $p <= 0.92));
    }

    public function test_legs_only_use_markets_bookmakers_price(): void
    {
        config(['africode.accas.tiers' => [3]]);

        // Shots-on-target picks are strong but unplaceable at most African
        // books; a corners pick in the same fixture must be chosen instead.
        foreach ([0, 1, 2, 3] as $offset) {
            $this->predictedFixture([
                'shots_on_target|8.5|over' => 0.60,
                'team_sot_home|4.5|over' => 0.62,
                'team_goals_home|0.5|over' => 0.75,
                'corners|9.5|over' => 0.64,
            ], $offset);
        }

        app(AccumulatorBuilderService::class)->run();

        $this->assertGreaterThan(0, AccumulatorLeg::count(), 'a ticket should still be built');

        $markets = AccumulatorLeg::pluck('market')->unique();
        $this->assertSame(['corners'], $markets->values()->all(),
            'only bettable markets, and no sub-1.5 line');
    }

    public function test_banker_ticket_reaches_its_target_using_only_short_legs(): void
    {
        config([
            'africode.accas.tiers' => [],
            'africode.accas.banker.caps' => [1.25],
            'africode.accas.banker.targets' => [20],
            'africode.accas.banker.max_legs' => 25,
        ]);

        // Each fixture offers a 0.85 pick (fair odds 1.18, inside the cap)
        // and a much longer 0.60 pick that the cap must exclude.
        $this->manyPredictedFixtures(25, [
            'goals|2.5|over' => 0.85,
            'corners|9.5|over' => 0.60,
        ]);

        $summary = app(AccumulatorBuilderService::class)->run();

        $this->assertSame(['1.25/20x'], $summary['banker']['built']);

        $acca = Accumulator::with('legs')->firstOrFail();
        $this->assertSame(Accumulator::FAMILY_BANKER, $acca->family);
        $this->assertSame(1.25, $acca->max_leg_odds);
        $this->assertGreaterThanOrEqual(20, $acca->combined_odds);

        // Nothing longer than the cap, so it takes a lot of legs: the 1.667
        // corners pick would have got there in six.
        $this->assertTrue($acca->legs->every(fn ($leg) => (float) $leg->odds <= 1.25));
        $this->assertSame(['goals'], $acca->legs->pluck('market')->unique()->values()->all());
        $this->assertGreaterThan(15, $acca->legs_count);
        $this->assertLessThanOrEqual(25, $acca->legs_count);
    }

    public function test_banker_tier_is_skipped_rather_than_exceeding_the_leg_ceiling(): void
    {
        config([
            'africode.accas.tiers' => [],
            'africode.accas.banker.caps' => [1.25],
            'africode.accas.banker.targets' => [20],
            'africode.accas.banker.max_legs' => 10,
        ]);

        // Ten 1.18 legs multiply to about 5.1 — the card cannot reach 20x
        // within the ceiling, so the ticket is not offered at all.
        $this->manyPredictedFixtures(25, ['goals|2.5|over' => 0.85]);

        $summary = app(AccumulatorBuilderService::class)->run();

        $this->assertSame(['1.25/20x'], $summary['banker']['skipped']);
        $this->assertSame(0, Accumulator::count());
    }

    public function test_a_tier_reuses_spent_picks_rather_than_going_unavailable(): void
    {
        config([
            'africode.accas.tiers' => [3],
            'africode.accas.banker.caps' => [1.25],
            'africode.accas.banker.targets' => [20, 40],
            'africode.accas.banker.max_legs' => 25,
        ]);

        // 25 fixtures with one pick each: the 20x ticket takes ~14 of them,
        // leaving too few unspent for 40x. Confined to a two-day window that
        // is normal, so the tier is rebuilt from the full pool instead of
        // being dropped.
        $this->manyPredictedFixtures(25, ['goals|2.5|over' => 0.85]);

        $summary = app(AccumulatorBuilderService::class)->run();

        $this->assertSame(['1.25/20x', '1.25/40x'], $summary['banker']['built']);

        $classic = Accumulator::where('family', Accumulator::FAMILY_CLASSIC)->with('legs')->firstOrFail();
        $banker = Accumulator::where('family', Accumulator::FAMILY_BANKER)
            ->where('target_odds', 20)->with('legs')->firstOrFail();

        // Families never competed for picks in the first place.
        $shared = $classic->legs->pluck('prediction_market_id')
            ->intersect($banker->legs->pluck('prediction_market_id'));
        $this->assertNotEmpty($shared, 'families should be free to reuse each other picks');

        // Reuse is across tickets only — one leg per fixture still holds
        // inside any single ticket, since same-match legs are correlated.
        foreach (Accumulator::with('legs')->get() as $acca) {
            $this->assertSame(
                $acca->legs->count(),
                $acca->legs->pluck('fixture_id')->unique()->count(),
            );
        }
    }

    public function test_every_leg_falls_inside_a_two_day_window(): void
    {
        config([
            'africode.accas.tiers' => [3],
            'africode.accas.banker.caps' => [],
        ]);

        // A thin card today and tomorrow, a fat one a week out. Spread over
        // four dates, an unconstrained builder would mix them freely.
        $this->manyPredictedFixtures(2, ['goals|2.5|over' => 0.60], daysAhead: 1);
        $this->manyPredictedFixtures(2, ['goals|2.5|over' => 0.60], daysAhead: 2, teamOffset: 2);
        $this->manyPredictedFixtures(6, ['goals|2.5|over' => 0.60], daysAhead: 5, teamOffset: 4);

        app(AccumulatorBuilderService::class)->run();

        $acca = Accumulator::with('legs.fixture')->firstOrFail();
        $dates = $acca->legs
            ->map(fn ($leg) => $leg->fixture->kickoff_utc
                ->timezone(config('africode.display_timezone'))->toDateString())
            ->unique()->sort()->values();

        $this->assertLessThanOrEqual(2, $dates->count(), 'a ticket may span at most two dates');
        if ($dates->count() === 2) {
            $this->assertSame(
                1,
                (int) Carbon::parse($dates[0])->diffInDays(Carbon::parse($dates[1])),
                'and they must be consecutive',
            );
        }
    }

    public function test_a_ticket_takes_the_earliest_window_it_can_complete_in(): void
    {
        config([
            'africode.accas.tiers' => [3, 20],
            'africode.accas.banker.caps' => [],
        ]);

        $tz = config('africode.display_timezone');
        $soon = now('UTC')->addDays(2)->setTime(12, 0)->timezone($tz)->toDateString();
        $later = now('UTC')->addDays(5)->setTime(12, 0)->timezone($tz)->toDateString();

        // Three 1.67 legs on the near date reach 3x but not 20x; the fat
        // card three days later can carry both.
        $this->manyPredictedFixtures(3, ['goals|2.5|over' => 0.60], daysAhead: 2);
        $this->manyPredictedFixtures(10, ['goals|2.5|over' => 0.60], daysAhead: 5, teamOffset: 3);

        app(AccumulatorBuilderService::class)->run();

        $dateOf = fn (int $target) => Accumulator::where('target_odds', $target)
            ->with('legs.fixture')->firstOrFail()->legs
            ->map(fn ($leg) => $leg->fixture->kickoff_utc->timezone($tz)->toDateString())
            ->unique()->values()->all();

        $this->assertSame([$soon], $dateOf(3), 'the small ticket stays on the nearest date');
        $this->assertSame([$later], $dateOf(20), 'the big one waits for a card that can carry it');
    }

    public function test_settlement_resolves_accas_from_leg_outcomes(): void
    {
        config(['africode.accas.tiers' => [3]]);

        $this->predictedFixture(['goals|2.5|over' => 0.55], 0);
        $this->predictedFixture(['goals|2.5|over' => 0.55], 1);
        app(AccumulatorBuilderService::class)->run();

        $acca = Accumulator::with('legs')->first();
        $marketIds = $acca->legs->pluck('prediction_market_id');

        // One leg won, one still pending -> acca stays pending.
        PredictionMarket::whereKey($marketIds[0])->update(['outcome' => 'won', 'settled_at' => now()]);
        app(SettlePredictionsService::class)->run();
        $this->assertSame('pending', $acca->fresh()->outcome);

        // Second leg void (postponed) -> voids drop out, remaining won -> won.
        PredictionMarket::whereKey($marketIds[1])->update(['outcome' => 'void', 'settled_at' => now()]);
        app(SettlePredictionsService::class)->run();
        $this->assertSame('won', $acca->fresh()->outcome);

        // A lost leg loses the ticket regardless.
        $acca2 = Accumulator::create([
            'generated_at' => now(), 'target_odds' => 3, 'combined_odds' => 3.3,
            'combined_probability' => 0.3, 'legs_count' => 2,
        ]);
        foreach ([['won', 0], ['lost', 1]] as [$outcome, $index]) {
            $market = PredictionMarket::whereKey($marketIds[$index])->first();
            $market->update(['outcome' => $outcome]);
            AccumulatorLeg::create([
                'accumulator_id' => $acca2->id, 'prediction_market_id' => $market->id,
                'fixture_id' => $market->prediction->fixture_id, 'market' => $market->market,
                'line' => $market->line, 'direction' => $market->direction,
                'probability' => $market->probability, 'odds' => 1.8,
            ]);
        }
        app(SettlePredictionsService::class)->run();
        $this->assertSame('lost', $acca2->fresh()->outcome);
    }

    public function test_page_shows_both_families_with_unavailable_states(): void
    {
        config([
            'africode.accas.tiers' => [3, 10000],
            'africode.accas.banker.caps' => [1.25, 1.60],
            'africode.accas.banker.targets' => [20, 40],
        ]);

        $this->predictedFixture(['goals|2.5|over' => 0.55], 0);
        $this->predictedFixture(['goals|2.5|over' => 0.55], 1);
        app(AccumulatorBuilderService::class)->run();

        $this->get('/accumulators')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Accumulators', false)
                ->count('families', 2)
                // Classic: one group holding every configured tier.
                ->where('families.0.key', 'classic')
                ->count('families.0.groups', 1)
                ->count('families.0.groups.0.tickets', 2)
                ->where('families.0.groups.0.tickets.0.target', 3)
                ->where('families.0.groups.0.tickets.0.available', true)
                ->count('families.0.groups.0.tickets.0.legs', 2)
                ->where('families.0.groups.0.tickets.1.target', 10000)
                ->where('families.0.groups.0.tickets.1.available', false)
                // Banker: one group per cap, each offering every target.
                ->where('families.1.key', 'banker')
                ->count('families.1.groups', 2)
                ->where('families.1.groups.0.label', 'Max 1.25 per leg')
                ->count('families.1.groups.0.tickets', 2)
                ->where('families.1.groups.0.tickets.0.max_leg_odds', 1.25)
                // Two 1.82 legs cannot reach 20x, let alone inside the cap.
                ->where('families.1.groups.0.tickets.0.available', false)
                ->where('families.1.groups.1.label', 'Max 1.60 per leg')
                ->has('max_legs')
            );
    }

    public function test_a_ticket_whose_matches_have_started_leaves_the_accumulators_page(): void
    {
        config(['africode.accas.tiers' => [3]]);

        $this->predictedFixture(['goals|2.5|over' => 0.55], 0);
        $this->predictedFixture(['goals|2.5|over' => 0.55], 1);
        app(AccumulatorBuilderService::class)->run();

        // While its matches are still ahead, the ticket is on the shelf.
        $this->get('/accumulators')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('families.0.groups.0.tickets.0.available', true)
            ->where('families.0.groups.0.tickets.0.started', false)
        );
        $this->get('/accuracy')->assertInertia(fn (AssertableInertia $page) => $page
            ->count('accumulators', 0)
        );

        // Kick-off passes on every leg: it can no longer be backed.
        Fixture::query()->update(['kickoff_utc' => now('UTC')->subHours(3)]);

        $this->get('/accumulators')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('families.0.groups.0.tickets.0.available', false)
            ->where('families.0.groups.0.tickets.0.started', true)
            // Flagged as run, not as "never built".
            ->count('families.0.groups.0.tickets.0.legs', 0)
        );

        $this->get('/accuracy')->assertInertia(fn (AssertableInertia $page) => $page
            ->count('accumulators', 1)
            ->where('accumulators.0.target', 3)
            ->where('accumulators.0.outcome', 'pending')
            ->count('accumulators.0.legs', 2)
            ->has('accumulators.0.window')
        );
    }

    public function test_a_partly_played_ticket_stays_on_the_accumulators_page(): void
    {
        config(['africode.accas.tiers' => [3]]);

        $first = $this->predictedFixture(['goals|2.5|over' => 0.55], 0);
        $this->predictedFixture(['goals|2.5|over' => 0.55], 1);
        app(AccumulatorBuilderService::class)->run();

        // One leg has kicked off, the other has not — the ticket is still
        // live, because the day it belongs to is not over.
        $first->update(['kickoff_utc' => now('UTC')->subHour()]);

        $this->get('/accumulators')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('families.0.groups.0.tickets.0.available', true)
        );
        $this->get('/accuracy')->assertInertia(fn (AssertableInertia $page) => $page
            ->count('accumulators', 0)
        );
    }

    public function test_a_live_ticket_is_not_republished_by_the_next_run(): void
    {
        config(['africode.accas.tiers' => [3, 10]]);

        foreach (range(0, 5) as $offset) {
            $this->predictedFixture([
                'goals|2.5|over' => 0.55,
                'corners|9.5|over' => 0.60,
            ], $offset);
        }

        $first = app(AccumulatorBuilderService::class)->run();
        $this->assertSame([3, 10], $first['built']);

        // The job runs again the next two mornings against the same card.
        $second = app(AccumulatorBuilderService::class)->run();
        app(AccumulatorBuilderService::class)->run();

        $this->assertSame([], $second['built'], 'nothing new while the tickets stand');
        $this->assertSame([3, 10], $second['kept']);
        $this->assertSame(2, Accumulator::count(), 'one ticket per definition, not one per run');

        // And the page still shows them, even though they are not from the
        // newest build.
        $this->get('/accumulators')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('families.0.groups.0.tickets.0.available', true)
            ->where('families.0.groups.0.tickets.1.available', true)
        );
    }

    public function test_a_fresh_ticket_is_built_once_the_old_one_has_run(): void
    {
        config(['africode.accas.tiers' => [3]]);

        $this->predictedFixture(['goals|2.5|over' => 0.55], 0);
        $this->predictedFixture(['goals|2.5|over' => 0.55], 1);
        app(AccumulatorBuilderService::class)->run();

        // Those matches run; a new card appears.
        Fixture::query()->update(['kickoff_utc' => now('UTC')->subHours(3)]);
        $this->predictedFixture(['goals|2.5|over' => 0.55], 2);
        $this->predictedFixture(['goals|2.5|over' => 0.55], 3);

        $summary = app(AccumulatorBuilderService::class)->run();

        $this->assertSame([3], $summary['built'], 'the definition is free again');
        $this->assertSame(2, Accumulator::count());
        $this->assertSame(1, Accumulator::live()->count());
    }

    public function test_dedupe_command_collapses_tickets_published_more_than_once(): void
    {
        config(['africode.accas.tiers' => [3]]);

        $this->predictedFixture(['goals|2.5|over' => 0.55], 0);
        $this->predictedFixture(['goals|2.5|over' => 0.55], 1);

        // Reproduce what the old daily rebuild left behind.
        app(AccumulatorBuilderService::class)->run();
        $original = Accumulator::firstOrFail();
        foreach ([1, 2] as $day) {
            $copy = $original->replicate()->fill(['generated_at' => now()->addDays($day)]);
            $copy->save();
            foreach ($original->legs as $leg) {
                AccumulatorLeg::create($leg->only([
                    'prediction_market_id', 'fixture_id', 'market', 'line',
                    'direction', 'probability', 'odds',
                ]) + ['accumulator_id' => $copy->id]);
            }
        }
        $this->assertSame(3, Accumulator::count());

        // Reports without deleting unless asked.
        $this->artisan('africode:dedupe-accas')->assertSuccessful();
        $this->assertSame(3, Accumulator::count());

        $this->artisan('africode:dedupe-accas --apply')->assertSuccessful();

        $this->assertSame(1, Accumulator::count());
        $this->assertSame($original->id, Accumulator::firstOrFail()->id, 'the first published copy survives');
        $this->assertSame(2, AccumulatorLeg::count(), 'orphan legs go with it');
    }

    public function test_dedupe_command_leaves_one_standing_ticket_per_definition(): void
    {
        config(['africode.accas.tiers' => [3]]);

        $this->predictedFixture(['goals|2.5|over' => 0.55, 'corners|9.5|over' => 0.60], 0);
        $this->predictedFixture(['goals|2.5|over' => 0.55, 'corners|9.5|over' => 0.60], 1);

        app(AccumulatorBuilderService::class)->run();
        $original = Accumulator::firstOrFail();

        // A near-copy: same definition, still live, one different leg — the
        // shape the old daily rebuild produced whenever the pool shifted.
        $nearCopy = $original->replicate()->fill(['generated_at' => now()->addDay()]);
        $nearCopy->save();
        foreach (PredictionMarket::where('market', 'corners')->take(2)->get() as $market) {
            AccumulatorLeg::create([
                'accumulator_id' => $nearCopy->id,
                'prediction_market_id' => $market->id,
                'fixture_id' => $market->prediction->fixture_id,
                'market' => 'corners', 'line' => 9.5, 'direction' => 'over',
                'probability' => 0.60, 'odds' => 1.667,
            ]);
        }

        $this->assertSame(2, Accumulator::live()->count());

        $this->artisan('africode:dedupe-accas --apply')->assertSuccessful();

        $this->assertSame(1, Accumulator::live()->count(), 'only one ticket may stand per definition');
        $this->assertSame($original->id, Accumulator::firstOrFail()->id);
    }

    public function test_job_and_command_wiring(): void
    {
        GenerateAccumulatorsJob::dispatchSync();
        $this->assertSame(PipelineRun::STATUS_SUCCESS, PipelineRun::latest('id')->first()->status);

        Queue::fake();
        $this->artisan('africode:generate-accas')->assertSuccessful();
        Queue::assertPushed(GenerateAccumulatorsJob::class);
    }
}
