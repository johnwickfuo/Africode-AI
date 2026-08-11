<?php

namespace Tests\Feature;

use App\Models\Accumulator;
use App\Models\Fixture;
use App\Models\FixtureOdds;
use App\Models\Prediction;
use App\Models\PredictionMarket;
use App\Models\Team;
use App\Services\Accas\AccumulatorBuilderService;
use App\Services\Odds\MarketPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        config(['africode.odds.margin' => [
            'goals' => 0.06,
            'corners' => 0.09,
            'default' => 0.08,
        ]]);
    }

    public function test_a_pick_nobody_prices_is_marked_up_by_the_market_margin(): void
    {
        $pricing = app(MarketPricing::class);

        // An 80% call is fair at 1.25; on a market taking 9% a book offers
        // 1/(0.80 x 1.09) = 1.147.
        $corners = $pricing->price('corners', 9.5, 'over', 0.80, null);

        $this->assertEqualsWithDelta(1.147, $corners['odds'], 0.001);
        $this->assertEqualsWithDelta(1.250, $corners['model_odds'], 0.001);
        $this->assertFalse($corners['priced'], 'no source publishes corners');

        // Markets differ: the same probability on goals keeps more.
        $goals = $pricing->price('goals', 3.5, 'under', 0.80, null);
        $this->assertGreaterThan($corners['odds'], $goals['odds']);
    }

    public function test_a_real_published_price_beats_the_estimate(): void
    {
        $pricing = app(MarketPricing::class);
        $odds = new FixtureOdds([
            'home_odds' => 2.10, 'draw_odds' => 3.60, 'away_odds' => 3.50,
            'over25_odds' => 1.83, 'under25_odds' => 1.95,
        ]);

        // 1X2 and the 2.5 line are published, so the model's own view of the
        // price is not used at all.
        $result = $pricing->price('result', null, 'home', 0.62, $odds);
        $this->assertSame(2.10, $result['odds']);
        $this->assertTrue($result['priced']);

        $goals25 = $pricing->price('goals', 2.5, 'under', 0.58, $odds);
        $this->assertSame(1.95, $goals25['odds']);
        $this->assertTrue($goals25['priced']);

        // A line nobody publishes falls back to the estimate even though the
        // fixture has odds attached.
        $goals35 = $pricing->price('goals', 3.5, 'under', 0.80, $odds);
        $this->assertFalse($goals35['priced']);
    }

    public function test_tickets_are_built_to_the_price_a_slip_would_pay(): void
    {
        config([
            'africode.accas.tiers' => [3],
            'africode.accas.banker.caps' => [],
            'africode.odds.margin' => ['default' => 0.08],
        ]);

        $teams = Team::orderBy('id')->get();
        foreach (range(0, 9) as $offset) {
            $fixture = Fixture::create([
                'league_id' => $teams[$offset * 2]->league_id,
                'season' => '2026-2027',
                'home_team_id' => $teams[$offset * 2]->id,
                'away_team_id' => $teams[$offset * 2 + 1]->id,
                'kickoff_utc' => now('UTC')->addDays(2)->setTime(12, 0)->addMinutes($offset),
                'status' => Fixture::STATUS_SCHEDULED,
            ]);
            $prediction = Prediction::create([
                'fixture_id' => $fixture->id, 'generated_at' => now()->subHour(),
                'model_version' => 'v1.1.0', 'best_bet_market' => 'corners',
                'best_bet_line' => 9.5, 'best_bet_direction' => 'over',
                'best_bet_probability' => 0.6, 'headline_text' => 'test',
            ]);
            PredictionMarket::create([
                'prediction_id' => $prediction->id, 'market' => 'corners',
                'line' => 9.5, 'direction' => 'over', 'probability' => 0.60,
                'confidence_margin' => 0.10,
            ]);
        }

        app(AccumulatorBuilderService::class)->run();

        $acca = Accumulator::with('legs')->firstOrFail();

        // The advertised total is the one that clears the target...
        $this->assertGreaterThanOrEqual(3.0, $acca->combined_odds);
        // ...and it is lower than the model's fair total, which is what the
        // ticket used to advertise and what made slips come back short.
        $this->assertGreaterThan($acca->combined_odds, $acca->model_combined_odds);

        // The win chance stays the model's, not 1/price — the bookmaker's
        // margin is not part of the ticket's chance of landing.
        $this->assertEqualsWithDelta(
            $acca->legs->reduce(fn ($carry, $leg) => $carry * $leg->probability, 1.0),
            $acca->combined_probability,
            0.0001,
        );
        $this->assertLessThan(1 / $acca->combined_odds, $acca->combined_probability);

        foreach ($acca->legs as $leg) {
            $this->assertLessThan($leg->model_odds, $leg->odds, 'every leg is marked down');
        }
    }

    public function test_a_pick_that_pays_almost_nothing_is_not_worth_a_leg(): void
    {
        config([
            'africode.accas.tiers' => [3],
            'africode.accas.banker.caps' => [],
            'africode.accas.leg_min_odds' => 1.05,
            'africode.odds.margin' => ['default' => 0.08],
        ]);

        $teams = Team::orderBy('id')->get();
        foreach (range(0, 9) as $offset) {
            $fixture = Fixture::create([
                'league_id' => $teams[$offset * 2]->league_id,
                'season' => '2026-2027',
                'home_team_id' => $teams[$offset * 2]->id,
                'away_team_id' => $teams[$offset * 2 + 1]->id,
                'kickoff_utc' => now('UTC')->addDays(2)->setTime(12, 0)->addMinutes($offset),
                'status' => Fixture::STATUS_SCHEDULED,
            ]);
            $prediction = Prediction::create([
                'fixture_id' => $fixture->id, 'generated_at' => now()->subHour(),
                'model_version' => 'v1.1.0', 'best_bet_market' => 'goals',
                'best_bet_line' => 4.5, 'best_bet_direction' => 'under',
                'best_bet_probability' => 0.91, 'headline_text' => 'test',
            ]);
            // 0.91 is fair at 1.10 but pays 1.017 once margin is taken:
            // all of the risk, none of the return.
            PredictionMarket::create([
                'prediction_id' => $prediction->id, 'market' => 'goals',
                'line' => 4.5, 'direction' => 'under', 'probability' => 0.91,
                'confidence_margin' => 0.41,
            ]);
            PredictionMarket::create([
                'prediction_id' => $prediction->id, 'market' => 'corners',
                'line' => 9.5, 'direction' => 'over', 'probability' => 0.60,
                'confidence_margin' => 0.10,
            ]);
        }

        app(AccumulatorBuilderService::class)->run();

        $markets = Accumulator::with('legs')->firstOrFail()->legs->pluck('market')->unique();
        $this->assertSame(['corners'], $markets->values()->all());
    }
}
