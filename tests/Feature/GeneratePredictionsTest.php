<?php

namespace Tests\Feature;

use App\Jobs\GeneratePredictionsJob;
use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\PipelineRun;
use App\Models\Prediction;
use App\Models\PredictionMarket;
use App\Models\Referee;
use App\Models\Team;
use App\Models\TeamProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Integration tests that execute the real scripts/predict.py — verifying
 * the export -> model -> import loop end to end.
 */
class GeneratePredictionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $python = new Process([config('africode.python_bin', 'python3'), '--version']);
        $python->run();
        if (! $python->isSuccessful()) {
            $this->markTestSkipped('python3 is not available in this environment.');
        }

        $this->seed();

        $dir = sys_get_temp_dir().'/predict_'.uniqid();
        mkdir($dir);
        config([
            'africode.predict.input_path' => $dir.'/predict_input.json',
            'africode.predict.output_path' => $dir.'/predictions_latest.json',
        ]);
    }

    private function team(string $name): Team
    {
        return Team::where('name', $name)->firstOrFail();
    }

    private function profile(Team $team, array $overrides = []): TeamProfile
    {
        return TeamProfile::create($overrides + [
            'team_id' => $team->id,
            'season' => '2025-2026',
            'matches_played' => 10,
            'attack_strength' => 1.1,
            'defence_strength' => 0.95,
            'home_advantage_factor' => 1.15,
            'xg_for_avg' => 1.6,
            'xg_against_avg' => 1.2,
            'corners_for_avg' => 5.5,
            'corners_against_avg' => 4.8,
            'crosses_avg' => 19.0,
            'cards_avg' => 2.1,
            'fouls_committed_avg' => 11.0,
            'fouls_drawn_avg' => 10.5,
            'sot_for_avg' => 4.8,
            'sot_against_avg' => 3.9,
        ]);
    }

    private function historyFixture(Team $home, Team $away, string $kickoff): void
    {
        $fixture = Fixture::create([
            'league_id' => $home->league_id,
            'season' => '2025-2026',
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'kickoff_utc' => $kickoff,
            'status' => Fixture::STATUS_FINISHED,
            'home_goals' => 2,
            'away_goals' => 1,
        ]);

        foreach ([[true, $home], [false, $away]] as [$isHome, $team]) {
            MatchStat::create([
                'fixture_id' => $fixture->id,
                'team_id' => $team->id,
                'is_home' => $isHome,
                'goals' => $isHome ? 2 : 1,
                'xg' => $isHome ? 1.8 : 1.1,
                'xga' => $isHome ? 1.1 : 1.8,
                'shots' => 12,
                'shots_on_target' => $isHome ? 5 : 4,
                'shots_on_target_against' => $isHome ? 4 : 5,
                'corners_for' => $isHome ? 6 : 4,
                'corners_against' => $isHome ? 4 : 6,
                'crosses' => 18,
                'fouls_committed' => 11,
                'fouls_drawn' => 10,
                'yellows' => 2,
                'reds' => 0,
                'possession' => 50.0,
                'source' => 'fbref',
            ]);
        }
    }

    private function upcomingFixture(Team $home, Team $away, ?Referee $referee = null): Fixture
    {
        return Fixture::create([
            'league_id' => $home->league_id,
            'season' => '2025-2026',
            'matchday' => 22,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'kickoff_utc' => now('UTC')->addDays(3),
            'status' => Fixture::STATUS_SCHEDULED,
            'referee_id' => $referee?->id,
            'is_derby' => true,
        ]);
    }

    public function test_generates_prediction_with_best_bet_and_all_markets(): void
    {
        $arsenal = $this->team('Arsenal');
        $spurs = $this->team('Tottenham Hotspur');

        // League history for averages + NB dispersion inputs.
        $this->historyFixture($arsenal, $this->team('Chelsea'), '2025-08-16 15:00:00');
        $this->historyFixture($this->team('Fulham'), $spurs, '2025-08-23 15:00:00');
        $this->historyFixture($this->team('Everton'), $this->team('Burnley'), '2025-08-30 15:00:00');

        $this->profile($arsenal, ['attack_strength' => 1.25, 'defence_strength' => 0.85]);
        $this->profile($spurs, ['attack_strength' => 0.95, 'defence_strength' => 1.1, 'cards_avg' => 2.6]);

        $referee = Referee::create([
            'name' => 'Michael Oliver',
            'matches_officiated' => 30,
            'avg_yellows_per_match' => 4.6,
            'avg_reds_per_match' => 0.2,
            'avg_fouls_per_match' => 21.0,
        ]);

        $fixture = $this->upcomingFixture($arsenal, $spurs, $referee);

        GeneratePredictionsJob::dispatchSync();

        $prediction = Prediction::where('fixture_id', $fixture->id)->first();
        $this->assertNotNull($prediction, 'a prediction row must be created');
        $this->assertSame('v1.1.0', $prediction->model_version);
        $this->assertStringContainsString('%', $prediction->headline_text);
        $this->assertGreaterThanOrEqual(0.5, $prediction->best_bet_probability);
        $this->assertLessThanOrEqual(0.92, $prediction->best_bet_probability, 'triviality ceiling respected');

        $markets = PredictionMarket::where('prediction_id', $prediction->id)->get();
        $this->assertSame(39, $markets->count(), '1 result + 5+1+3+3 goals/btts + 5+3+3 corners + 4 cards + 3+4+4 SoT');
        $this->assertEqualsCanonicalizing(
            ['result', 'goals', 'btts', 'team_goals_home', 'team_goals_away', 'corners', 'team_corners_home',
                'team_corners_away', 'cards', 'shots_on_target', 'team_sot_home', 'team_sot_away'],
            $markets->pluck('market')->unique()->values()->all(),
        );
        $this->assertTrue($markets->every(
            // 1X2 favourites can sit below 50% (three outcomes); every
            // binary market must carry the favoured side.
            fn (PredictionMarket $row) => $row->probability >= ($row->market === 'result' ? 1 / 3 : 0.5)
                && $row->probability <= 1.0
        ), 'stored rows carry the favoured side');

        $result = $markets->where('market', 'result');
        $this->assertCount(1, $result, 'one 1X2 pick per fixture');
        $this->assertContains($result->first()->direction, ['home', 'draw', 'away']);
        $this->assertNull($result->first()->line);
        $this->assertTrue($markets->every(
            fn (PredictionMarket $row) => $row->outcome === PredictionMarket::OUTCOME_PENDING
        ));

        // Goals over-probabilities must decrease across ascending lines.
        $goalsOver = $markets->where('market', 'goals')->sortBy('line')
            ->map(fn ($row) => $row->direction === 'over' ? $row->probability : 1 - $row->probability)
            ->values();
        $this->assertTrue($goalsOver->every(
            fn ($p, $i) => $i === 0 || $p < $goalsOver[$i - 1]
        ), 'over-probability must fall as the line rises');

        $this->assertSame(PipelineRun::STATUS_SUCCESS, PipelineRun::lastSuccessfulRun('GeneratePredictionsJob')->status);
    }

    public function test_challenger_trains_and_predicts_alongside_the_champion(): void
    {
        // Profiles + ≥300 finished fixtures give the softmax challenger a
        // training set; the champion and challenger then both predict the
        // upcoming fixture, and only the champion is user-visible.
        $teams = Team::whereHas('league', fn ($q) => $q->where('code', 'PL'))->orderBy('id')->get();
        foreach ($teams as $team) {
            $this->profile($team, ['attack_strength' => 0.85 + ($team->id % 7) * 0.09]);
        }

        $created = 0;
        foreach ($teams as $home) {
            foreach ($teams as $away) {
                if ($home->id === $away->id || $created >= 310) {
                    continue;
                }
                $this->historyFixture($home, $away, now('UTC')->subDays(200 - intdiv($created, 3))->toDateTimeString());
                $created++;
            }
        }

        $fixture = $this->upcomingFixture($this->team('Arsenal'), $this->team('Chelsea'));

        GeneratePredictionsJob::dispatchSync();

        $champion = Prediction::champion()->where('fixture_id', $fixture->id)->first();
        $challenger = Prediction::where('is_challenger', true)->where('fixture_id', $fixture->id)->first();

        $this->assertNotNull($champion);
        $this->assertNotNull($challenger, 'challenger prediction must be stored');
        $this->assertSame('ml-1x2-v1.1.0', $challenger->model_version);

        $markets = PredictionMarket::where('prediction_id', $challenger->id)->get();
        $this->assertCount(1, $markets, 'challenger predicts only 1X2');
        $this->assertSame('result', $markets->first()->market);
        $this->assertContains($markets->first()->direction, ['home', 'draw', 'away']);
        $this->assertGreaterThan(1 / 3 - 0.01, $markets->first()->probability);
        $this->assertLessThanOrEqual(1.0, $markets->first()->probability);

        // The site never surfaces the challenger as its pick.
        $this->assertSame($champion->id, $fixture->predictions()->champion()->orderByDesc('generated_at')->first()->id);
    }

    public function test_promoted_team_without_history_gets_prior_based_prediction(): void
    {
        // Home side has a real profile; away side has NO profile at all —
        // the promoted-team prior must fill in so the fixture is predicted
        // from matchday 1 instead of being skipped.
        $arsenal = $this->team('Arsenal');
        $spurs = $this->team('Tottenham Hotspur');
        $this->profile($arsenal);
        $this->historyFixture($arsenal, $spurs, now('UTC')->subDays(30)->toDateTimeString());

        $fixture = $this->upcomingFixture($arsenal, $spurs);

        GeneratePredictionsJob::dispatchSync();

        $prediction = Prediction::champion()->where('fixture_id', $fixture->id)->first();
        $this->assertNotNull($prediction, 'prior-backed prediction created');

        $result = PredictionMarket::where('prediction_id', $prediction->id)
            ->where('market', 'result')->first();
        $this->assertNotNull($result);
        // Prior says promoted sides are weak: home favourite expected.
        $this->assertSame('home', $result->direction);
    }

    public function test_fixture_without_any_profiles_is_predicted_from_priors(): void
    {
        // Behavior change with promoted-team priors: a fixture where neither
        // side has a profile is no longer skipped — both sides fall back to
        // the prior, so season openers between data-less clubs get picks.
        $this->historyFixture($this->team('Everton'), $this->team('Burnley'), '2025-08-30 15:00:00');
        $fixture = $this->upcomingFixture($this->team('Arsenal'), $this->team('Tottenham Hotspur'));

        GeneratePredictionsJob::dispatchSync();

        $this->assertSame(1, Prediction::where('fixture_id', $fixture->id)->count());
        $this->assertSame(PipelineRun::STATUS_SUCCESS, PipelineRun::latest('id')->first()->status);
    }

    public function test_no_upcoming_fixtures_short_circuits_without_running_python(): void
    {
        config(['africode.python_bin' => '/definitely/not/python']);

        GeneratePredictionsJob::dispatchSync();

        $this->assertSame(0, Prediction::count());
        $this->assertSame(PipelineRun::STATUS_SUCCESS, PipelineRun::latest('id')->first()->status);
    }

    public function test_command_queues_the_job(): void
    {
        Queue::fake();

        $this->artisan('africode:generate-predictions')->assertSuccessful();

        Queue::assertPushed(GeneratePredictionsJob::class);
    }
}
