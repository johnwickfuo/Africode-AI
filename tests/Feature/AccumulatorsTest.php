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
    private function manyPredictedFixtures(int $count, array $markets): array
    {
        $teams = Team::orderBy('id')->get();
        $fixtures = [];

        foreach (range(0, $count - 1) as $offset) {
            $home = $teams[$offset * 2];
            $away = $teams[$offset * 2 + 1];

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

    public function test_families_keep_separate_ledgers_but_stay_distinct_inside_themselves(): void
    {
        config([
            'africode.accas.tiers' => [3],
            'africode.accas.banker.caps' => [1.25],
            'africode.accas.banker.targets' => [20, 40],
            'africode.accas.banker.max_legs' => 25,
        ]);

        $this->manyPredictedFixtures(25, ['goals|2.5|over' => 0.85]);

        app(AccumulatorBuilderService::class)->run();

        $classic = Accumulator::where('family', Accumulator::FAMILY_CLASSIC)->with('legs')->firstOrFail();
        $banker = Accumulator::where('family', Accumulator::FAMILY_BANKER)->with('legs')->firstOrFail();

        // Separate ledgers: the two families are allowed to land on the same
        // call, because nobody backs a classic and a banker ticket as a pair.
        $shared = $classic->legs->pluck('prediction_market_id')
            ->intersect($banker->legs->pluck('prediction_market_id'));
        $this->assertNotEmpty($shared, 'families should be free to reuse each other picks');

        // Inside the banker family the old rule still holds, which is also
        // why the 40x rung has nothing left to build from here.
        $bankerCalls = AccumulatorLeg::whereIn(
            'accumulator_id',
            Accumulator::where('family', Accumulator::FAMILY_BANKER)->pluck('id'),
        )->get()->map(fn ($leg) => $leg->fixture_id.'|'.$leg->market);
        $this->assertSame($bankerCalls->count(), $bankerCalls->unique()->count());
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

    public function test_job_and_command_wiring(): void
    {
        GenerateAccumulatorsJob::dispatchSync();
        $this->assertSame(PipelineRun::STATUS_SUCCESS, PipelineRun::latest('id')->first()->status);

        Queue::fake();
        $this->artisan('africode:generate-accas')->assertSuccessful();
        Queue::assertPushed(GenerateAccumulatorsJob::class);
    }
}
