<?php

namespace Tests\Feature;

use App\Jobs\SettlePredictionsJob;
use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\ModelAccuracy;
use App\Models\PipelineRun;
use App\Models\Prediction;
use App\Models\PredictionMarket;
use App\Models\Team;
use App\Services\Predictions\SettlePredictionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SettlePredictionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function team(string $name): Team
    {
        return Team::where('name', $name)->firstOrFail();
    }

    /**
     * Finished fixture: 2-1, corners 7+4=11, cards 2+3(+1 red)=6,
     * SoT 6+3=9.
     */
    private function finishedFixtureWithStats(bool $withStats = true): Fixture
    {
        $arsenal = $this->team('Arsenal');
        $spurs = $this->team('Tottenham Hotspur');

        $fixture = Fixture::create([
            'league_id' => $arsenal->league_id,
            'season' => '2025-2026',
            'home_team_id' => $arsenal->id,
            'away_team_id' => $spurs->id,
            'kickoff_utc' => now('UTC')->subDay(),
            'status' => Fixture::STATUS_FINISHED,
            'home_goals' => 2,
            'away_goals' => 1,
        ]);

        if ($withStats) {
            MatchStat::create([
                'fixture_id' => $fixture->id, 'team_id' => $arsenal->id, 'is_home' => true,
                'goals' => 2, 'corners_for' => 7, 'corners_against' => 4,
                'yellows' => 2, 'reds' => 0, 'shots_on_target' => 6, 'shots_on_target_against' => 3,
                'source' => 'fbref',
            ]);
            MatchStat::create([
                'fixture_id' => $fixture->id, 'team_id' => $spurs->id, 'is_home' => false,
                'goals' => 1, 'corners_for' => 4, 'corners_against' => 7,
                'yellows' => 3, 'reds' => 1, 'shots_on_target' => 3, 'shots_on_target_against' => 6,
                'source' => 'fbref',
            ]);
        }

        return $fixture;
    }

    private function predictionWithMarkets(Fixture $fixture, array $markets): Prediction
    {
        $prediction = Prediction::create([
            'fixture_id' => $fixture->id,
            'generated_at' => now()->subDays(2),
            'model_version' => 'v1.0.0',
            'best_bet_market' => $markets[0][0],
            'best_bet_line' => $markets[0][1],
            'best_bet_direction' => $markets[0][2],
            'best_bet_probability' => $markets[0][3],
            'headline_text' => 'test',
        ]);

        foreach ($markets as [$market, $line, $direction, $probability]) {
            PredictionMarket::create([
                'prediction_id' => $prediction->id,
                'market' => $market,
                'line' => $line,
                'direction' => $direction,
                'probability' => $probability,
                'confidence_margin' => abs($probability - 0.5),
            ]);
        }

        return $prediction;
    }

    public function test_settles_every_market_type_against_actuals(): void
    {
        $fixture = $this->finishedFixtureWithStats();

        // Actuals: goals 3, home 2, away 1, btts yes, corners 11 (7/4),
        // cards 6, SoT 9 (6/3).
        $prediction = $this->predictionWithMarkets($fixture, [
            ['result', null, 'home', 0.52],          // 2-1 home win  won
            ['result', null, 'draw', 0.30],          // not a draw  lost
            ['result', null, 'away', 0.28],          // not away  lost
            ['goals', 2.5, 'over', 0.64],            // 3 > 2.5  won
            ['goals', 3.5, 'over', 0.55],            // 3 < 3.5  lost
            ['btts', null, 'yes', 0.66],             // both scored  won
            ['team_goals_home', 1.5, 'over', 0.60],  // 2 > 1.5  won
            ['team_goals_away', 1.5, 'under', 0.71], // 1 < 1.5  won
            ['corners', 9.5, 'over', 0.78],          // 11 > 9.5  won
            ['corners', 11.5, 'over', 0.52],         // 11 < 11.5  lost
            ['team_corners_away', 4.5, 'under', 0.62], // 4 < 4.5  won
            ['cards', 5.5, 'over', 0.57],            // 6 > 5.5  won
            ['cards', 6.5, 'over', 0.51],            // 6 < 6.5  lost
            ['shots_on_target', 8.5, 'over', 0.63],  // 9 > 8.5  won
            ['team_sot_home', 5.5, 'over', 0.58],    // 6 > 5.5  won
        ]);

        $summary = app(SettlePredictionsService::class)->run();

        $this->assertSame(15, $summary['settled']);
        $this->assertSame(0, $summary['awaiting_stats']);

        $outcomes = PredictionMarket::where('prediction_id', $prediction->id)
            ->get()
            ->mapWithKeys(fn ($row) => [$row->market.'|'.$row->line.'|'.$row->direction => $row->outcome]);

        $this->assertSame('won', $outcomes['result||home']);
        $this->assertSame('lost', $outcomes['result||draw']);
        $this->assertSame('lost', $outcomes['result||away']);
        $this->assertSame('won', $outcomes['goals|2.5|over']);
        $this->assertSame('lost', $outcomes['goals|3.5|over']);
        $this->assertSame('won', $outcomes['btts||yes']);
        $this->assertSame('won', $outcomes['team_goals_home|1.5|over']);
        $this->assertSame('won', $outcomes['team_goals_away|1.5|under']);
        $this->assertSame('won', $outcomes['corners|9.5|over']);
        $this->assertSame('lost', $outcomes['corners|11.5|over']);
        $this->assertSame('won', $outcomes['team_corners_away|4.5|under']);
        $this->assertSame('won', $outcomes['cards|5.5|over']);
        $this->assertSame('lost', $outcomes['cards|6.5|over']);
        $this->assertSame('won', $outcomes['shots_on_target|8.5|over']);
        $this->assertSame('won', $outcomes['team_sot_home|5.5|over']);

        $this->assertSame(0, PredictionMarket::where('outcome', 'pending')->count());
        $this->assertNotNull(PredictionMarket::first()->settled_at);
    }

    public function test_stats_markets_stay_pending_until_fbref_import_lands(): void
    {
        $fixture = $this->finishedFixtureWithStats(withStats: false);

        $this->predictionWithMarkets($fixture, [
            ['goals', 2.5, 'over', 0.64],   // settleable from the result alone
            ['corners', 9.5, 'over', 0.78], // needs match stats -> pending
            ['cards', 3.5, 'over', 0.61],   // needs match stats -> pending
        ]);

        $summary = app(SettlePredictionsService::class)->run();

        $this->assertSame(1, $summary['settled']);
        $this->assertSame(2, $summary['awaiting_stats']);
        $this->assertSame('won', PredictionMarket::where('market', 'goals')->first()->outcome);
        $this->assertSame('pending', PredictionMarket::where('market', 'corners')->first()->outcome);
    }

    public function test_postponed_fixture_voids_its_markets(): void
    {
        $fixture = $this->finishedFixtureWithStats();
        $fixture->update(['status' => Fixture::STATUS_POSTPONED, 'home_goals' => null, 'away_goals' => null]);

        $this->predictionWithMarkets($fixture, [['goals', 2.5, 'over', 0.64]]);

        $summary = app(SettlePredictionsService::class)->run();

        $this->assertSame(1, $summary['voided']);
        $this->assertSame('void', PredictionMarket::first()->outcome);
    }

    public function test_scheduled_fixtures_are_left_alone(): void
    {
        $fixture = $this->finishedFixtureWithStats();
        $fixture->update(['status' => Fixture::STATUS_SCHEDULED]);

        $this->predictionWithMarkets($fixture, [['goals', 2.5, 'over', 0.64]]);

        app(SettlePredictionsService::class)->run();

        $this->assertSame('pending', PredictionMarket::first()->outcome);
    }

    public function test_model_accuracy_is_rebuilt_from_settled_rows(): void
    {
        $fixture = $this->finishedFixtureWithStats();
        $this->predictionWithMarkets($fixture, [
            ['corners', 9.5, 'over', 0.78],  // won
            ['corners', 11.5, 'over', 0.60], // lost
            ['btts', null, 'yes', 0.66],     // won
        ]);

        // A stale summary row that must not survive the rebuild.
        ModelAccuracy::create(['market' => 'goals', 'line_bucket' => '99.5', 'total_settled' => 1, 'hits' => 1]);

        app(SettlePredictionsService::class)->run();

        $this->assertNull(ModelAccuracy::where('line_bucket', '99.5')->first());

        $corners95 = ModelAccuracy::where(['market' => 'corners', 'line_bucket' => '9.5'])->first();
        $this->assertSame(1, $corners95->total_settled);
        $this->assertSame(1, $corners95->hits);
        $this->assertEqualsWithDelta(1.0, $corners95->hit_rate, 0.0001);
        $this->assertEqualsWithDelta(0.78 - 1.0, $corners95->calibration_gap, 0.0001);

        $btts = ModelAccuracy::where(['market' => 'btts', 'line_bucket' => 'yes'])->first();
        $this->assertNotNull($btts);
        $this->assertEqualsWithDelta(1.0, $btts->hit_rate, 0.0001);
    }

    public function test_job_and_command_wiring(): void
    {
        SettlePredictionsJob::dispatchSync();
        $this->assertSame(PipelineRun::STATUS_SUCCESS, PipelineRun::latest('id')->first()->status);

        Queue::fake();
        $this->artisan('africode:settle-predictions')->assertSuccessful();
        Queue::assertPushed(SettlePredictionsJob::class);
    }
}
