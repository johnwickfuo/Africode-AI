<?php

namespace Tests\Feature;

use App\Jobs\ImportOddsJob;
use App\Models\Fixture;
use App\Models\FixtureOdds;
use App\Models\PipelineRun;
use App\Models\Prediction;
use App\Models\PredictionMarket;
use App\Models\Team;
use App\Services\FootballDataCoUk\CsvStatsImportService;
use App\Services\Odds\ImportOddsService;
use App\Services\Odds\ValueBets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class OddsAndValueTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES_HEADER = 'Div,Date,Time,HomeTeam,AwayTeam,B365H,B365D,B365A,B365>2.5,B365<2.5,AvgH,AvgD,AvgA,Avg>2.5,Avg<2.5';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function team(string $name): Team
    {
        return Team::where('name', $name)->firstOrFail();
    }

    private function upcomingFixture(Team $home, Team $away): Fixture
    {
        return Fixture::create([
            'league_id' => $home->league_id, 'season' => '2025-2026',
            'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'kickoff_utc' => now('UTC')->addDays(2), 'status' => Fixture::STATUS_SCHEDULED,
        ]);
    }

    private function predictionWith(Fixture $fixture, array $markets): Prediction
    {
        $prediction = Prediction::create([
            'fixture_id' => $fixture->id, 'generated_at' => now(), 'model_version' => 'v1.1.0',
            'best_bet_market' => 'result', 'best_bet_line' => null,
            'best_bet_direction' => 'home', 'best_bet_probability' => 0.55,
            'headline_text' => 'test',
        ]);
        foreach ($markets as [$market, $line, $direction, $probability]) {
            PredictionMarket::create([
                'prediction_id' => $prediction->id, 'market' => $market, 'line' => $line,
                'direction' => $direction, 'probability' => $probability,
                'confidence_margin' => abs($probability - 0.5),
            ]);
        }

        return $prediction;
    }

    public function test_imports_upcoming_odds_from_fixtures_csv(): void
    {
        $fixture = $this->upcomingFixture($this->team('Arsenal'), $this->team('Tottenham Hotspur'));

        Http::fake([
            'www.football-data.co.uk/fixtures.csv' => Http::response(
                "\u{FEFF}".self::FIXTURES_HEADER."\n"
                .'E0,'.now()->addDays(2)->format('d/m/Y').',17:30,Arsenal,Tottenham,1.85,3.9,4.2,1.8,2.0,1.90,4.0,4.10,1.85,1.95'."\n"
                // Unknown pairing: counted as unmatched, not fatal.
                .'E0,'.now()->addDays(2)->format('d/m/Y').',15:00,Wrexham,Chester,2.0,3.0,4.0,,,,,,,',
            ),
        ]);

        $summary = app(ImportOddsService::class)->run();

        $this->assertSame(1, $summary['odds_saved']);
        $this->assertSame(1, $summary['fixtures_unmatched']);

        $odds = FixtureOdds::where('fixture_id', $fixture->id)->first();
        $this->assertSame(1.90, $odds->home_odds, 'market average preferred over Bet365');
        $this->assertSame(4.0, $odds->draw_odds);
        $this->assertSame(1.85, $odds->over25_odds);
    }

    public function test_results_csv_import_captures_closing_odds(): void
    {
        Sleep::fake(); // skip inter-download pacing

        Http::fake([
            'www.football-data.co.uk/mmz4281/2526/E0.csv' => Http::response(
                "\u{FEFF}Div,Date,Time,HomeTeam,AwayTeam,FTHG,FTAG,Referee,HS,AS,HST,AST,HF,AF,HC,AC,HY,AY,HR,AR,B365H,B365D,B365A,Avg>2.5,Avg<2.5\n"
                .'E0,15/08/2025,20:00,Liverpool,Bournemouth,4,2,A Taylor,19,10,10,3,7,10,6,7,1,2,0,0,1.30,5.75,9.00,1.53,2.55',
            ),
            'www.football-data.co.uk/*' => Http::response('Div,Date'),
        ]);

        app(CsvStatsImportService::class)->run(['2526']);

        $odds = FixtureOdds::first();
        $this->assertNotNull($odds, 'closing odds stored from results CSV');
        $this->assertSame(1.30, $odds->home_odds);
        $this->assertSame(1.53, $odds->over25_odds);
    }

    public function test_value_bets_strip_overround_and_flag_edges(): void
    {
        $fixture = $this->upcomingFixture($this->team('Arsenal'), $this->team('Tottenham Hotspur'));
        $prediction = $this->predictionWith($fixture, [
            ['result', null, 'home', 0.60],
            ['goals', 2.5, 'over', 0.55],
        ])->load('markets');

        // 1/1.9 + 1/4.0 + 1/4.1 ≈ 1.0202 overround; implied home ≈ 0.5159.
        $odds = FixtureOdds::create([
            'fixture_id' => $fixture->id, 'home_odds' => 1.90, 'draw_odds' => 4.00,
            'away_odds' => 4.10, 'over25_odds' => 1.85, 'under25_odds' => 1.95,
            'fetched_at' => now(),
        ]);

        $rows = app(ValueBets::class)->compare($prediction, $odds);

        $this->assertCount(2, $rows);
        [$result, $goals] = $rows;

        $this->assertSame('result', $result['market']);
        $this->assertEqualsWithDelta(0.5159, $result['implied_probability'], 0.001);
        $this->assertEqualsWithDelta(0.0841, $result['edge'], 0.001);
        $this->assertTrue($result['is_value'], '8.4% edge beats the 5% threshold');

        $this->assertSame('goals_2.5', $goals['market']);
        $this->assertEqualsWithDelta(0.5132, $goals['implied_probability'], 0.001);
        $this->assertFalse($goals['is_value'], '3.7% edge is below the threshold');

        $best = app(ValueBets::class)->bestEdge($prediction, $odds);
        $this->assertSame('result', $best['market']);

        $this->assertSame([], app(ValueBets::class)->compare($prediction, null), 'no odds, no rows');
    }

    public function test_dashboard_and_match_page_expose_value_data(): void
    {
        $fixture = $this->upcomingFixture($this->team('Arsenal'), $this->team('Tottenham Hotspur'));
        $this->predictionWith($fixture, [['result', null, 'home', 0.62]]);
        FixtureOdds::create([
            'fixture_id' => $fixture->id, 'home_odds' => 2.10, 'draw_odds' => 3.60,
            'away_odds' => 3.50, 'fetched_at' => now(),
        ]);

        $this->withoutExceptionHandling();
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard', false)
                ->where('groups.0.fixtures.0.value.market', 'result')
                ->where('groups.0.fixtures.0.value.pick', 'home'));

        $this->get("/match/{$fixture->id}")
            ->assertInertia(fn ($page) => $page
                ->component('MatchDetail', false)
                ->where('value_bets.0.market', 'result')
                ->has('odds'));
    }

    public function test_job_and_command_wiring(): void
    {
        Http::fake(['www.football-data.co.uk/*' => Http::response(self::FIXTURES_HEADER)]);

        ImportOddsJob::dispatchSync();
        $this->assertSame(PipelineRun::STATUS_SUCCESS, PipelineRun::latest('id')->first()->status);

        Queue::fake();
        $this->artisan('africode:import-odds')->assertSuccessful();
        Queue::assertPushed(ImportOddsJob::class);
    }
}
