<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\PipelineRun;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\Prediction;
use App\Models\PredictionMarket;
use App\Models\Team;
use App\Models\TeamProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PagesTest extends TestCase
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

    private function upcomingFixtureWithPrediction(): array
    {
        $arsenal = $this->team('Arsenal');
        $spurs = $this->team('Tottenham Hotspur');

        $fixture = Fixture::create([
            'league_id' => $arsenal->league_id,
            'season' => '2025-2026',
            'matchday' => 22,
            'home_team_id' => $arsenal->id,
            'away_team_id' => $spurs->id,
            'kickoff_utc' => now('UTC')->addDays(2),
            'status' => Fixture::STATUS_SCHEDULED,
            'is_derby' => true,
        ]);

        $prediction = Prediction::create([
            'fixture_id' => $fixture->id,
            'generated_at' => now(),
            'model_version' => 'v1.0.0',
            'best_bet_market' => 'corners',
            'best_bet_line' => 9.5,
            'best_bet_direction' => 'over',
            'best_bet_probability' => 0.78,
            'headline_text' => 'Over 9.5 corners — 78%',
        ]);

        foreach ([
            ['corners', 9.5, 'over', 0.78],
            ['goals', 2.5, 'over', 0.61],
            ['btts', null, 'yes', 0.66],
            ['cards', 3.5, 'under', 0.64],
        ] as [$market, $line, $direction, $probability]) {
            PredictionMarket::create([
                'prediction_id' => $prediction->id,
                'market' => $market,
                'line' => $line,
                'direction' => $direction,
                'probability' => $probability,
                'confidence_margin' => abs($probability - 0.5),
            ]);
        }

        return [$fixture, $prediction];
    }

    public function test_dashboard_shows_fixture_with_best_bet_headline(): void
    {
        [$fixture] = $this->upcomingFixtureWithPrediction();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard', false)
                ->count('groups', 1)
                ->where('groups.0.league.code', $fixture->league->code)
                ->where('groups.0.preview', false)
                ->count('groups.0.fixtures', 1)
                ->where('groups.0.fixtures.0.id', $fixture->id)
                ->where('groups.0.fixtures.0.is_derby', true)
                ->where('groups.0.fixtures.0.best_bet.headline', 'Over 9.5 corners — 78%')
                ->where('groups.0.fixtures.0.best_bet.probability', 0.78)
            );
    }

    public function test_dashboard_groups_by_league_and_previews_seasons_starting_later(): void
    {
        // In play: a Premier League match inside the 14-day window.
        [$inWindow] = $this->upcomingFixtureWithPrediction();

        // Not yet: the Bundesliga opens well beyond the window, which used
        // to make the league vanish from the site entirely.
        $bayern = $this->team('Bayern Munich');
        $leipzig = $this->team('RB Leipzig');
        $opener = Fixture::create([
            'league_id' => $bayern->league_id,
            'season' => '2026-2027',
            'matchday' => 1,
            'home_team_id' => $bayern->id,
            'away_team_id' => $leipzig->id,
            'kickoff_utc' => now('UTC')->addDays(25),
            'status' => Fixture::STATUS_SCHEDULED,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard', false)
                ->count('groups', 2)
                // Leagues actually playing lead, previews fall to the bottom.
                ->where('groups.0.league.code', $inWindow->league->code)
                ->where('groups.0.preview', false)
                ->where('groups.0.starts_in_days', null)
                ->where('groups.1.league.code', 'BL1')
                ->where('groups.1.preview', true)
                ->where('groups.1.starts_in_days', 25)
                ->where('groups.1.fixtures.0.id', $opener->id)
            );
    }

    public function test_match_detail_shows_prediction_profiles_and_head_to_head(): void
    {
        [$fixture] = $this->upcomingFixtureWithPrediction();

        TeamProfile::create([
            'team_id' => $fixture->home_team_id,
            'season' => '2025-2026',
            'matches_played' => 10,
            'attack_strength' => 1.2,
            'corners_for_avg' => 6.1,
        ]);

        // A previous meeting for the head-to-head block.
        Fixture::create([
            'league_id' => $fixture->league_id,
            'season' => '2024-2025',
            'home_team_id' => $fixture->away_team_id,
            'away_team_id' => $fixture->home_team_id,
            'kickoff_utc' => '2025-01-15 17:30:00',
            'status' => Fixture::STATUS_FINISHED,
            'home_goals' => 1,
            'away_goals' => 2,
        ]);

        // Key players: two Arsenal appearances this season.
        $saka = Player::create([
            'team_id' => $fixture->home_team_id, 'name' => 'Bukayo Saka',
            'nationality' => 'ENG', 'position' => 'RW', 'last_seen_at' => now(),
        ]);
        $finished = Fixture::create([
            'league_id' => $fixture->league_id, 'season' => '2025-2026',
            'home_team_id' => $fixture->home_team_id,
            'away_team_id' => $this->team('Chelsea')->id,
            'kickoff_utc' => now('UTC')->subDays(10),
            'status' => Fixture::STATUS_FINISHED, 'home_goals' => 2, 'away_goals' => 0,
        ]);
        PlayerMatchStat::create([
            'player_id' => $saka->id, 'fixture_id' => $finished->id,
            'team_id' => $fixture->home_team_id, 'minutes' => 90,
            'goals' => 2, 'assists' => 1, 'yellows' => 1, 'reds' => 0,
        ]);

        $this->get("/match/{$fixture->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('MatchDetail', false)
                ->where('fixture.id', $fixture->id)
                ->where('fixture.is_derby', true)
                ->where('prediction.best_bet.headline', 'Over 9.5 corners — 78%')
                ->count('prediction.markets', 4)
                ->where('profiles.home.attack_strength', 1.2)
                ->count('head_to_head', 1)
                ->where('head_to_head.0.home_goals', 1)
                ->where('key_players.home.top_scorer.name', 'Bukayo Saka')
                ->where('key_players.home.top_scorer.value', 2)
                ->where('key_players.home.most_carded.name', 'Bukayo Saka')
                ->where('key_players.away', null) // backfill hasn't reached Spurs
            );
    }

    public function test_accuracy_page_computes_hit_rates_and_calibration(): void
    {
        [, $prediction] = $this->upcomingFixtureWithPrediction();

        // Settle: corners (the best bet) won, goals lost, btts won, cards won.
        $outcomes = ['corners' => 'won', 'goals' => 'lost', 'btts' => 'won', 'cards' => 'won'];
        foreach ($prediction->markets as $market) {
            $market->update(['outcome' => $outcomes[$market->market], 'settled_at' => now()->subDay()]);
        }

        $this->get('/accuracy')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Accuracy', false)
                ->where('windows.30.total_settled', 4)
                // Best Bet (corners over 9.5) won -> 100% on 1 settled.
                ->where('windows.30.best_bets.total', 1)
                ->where('windows.30.best_bets.hit_rate', fn ($rate) => $rate == 1.0)
                // corners market: 1/1 won.
                ->where('windows.30.markets', fn ($markets) => collect($markets)
                    ->firstWhere('market', 'corners')['hit_rate'] == 1.0)
                // goals market: 0/1.
                ->where('windows.30.markets', fn ($markets) => collect($markets)
                    ->firstWhere('market', 'goals')['hit_rate'] == 0.0)
                // 0.60-0.65 bucket holds goals(0.61, lost) + cards(0.64, won) -> 50%.
                ->where('windows.30.calibration', fn ($buckets) => collect($buckets)
                    ->firstWhere('from', 0.6)['hit_rate'] == 0.5)
                ->where('model_versions.0.version', 'v1.0.0')
                ->where('model_versions.0.total', 4)
                ->where('model_versions.0.hits', 3)
            );
    }

    public function test_history_lists_settled_rows_and_filters_by_market(): void
    {
        [, $prediction] = $this->upcomingFixtureWithPrediction();
        $prediction->fixture->update(['status' => Fixture::STATUS_FINISHED, 'home_goals' => 2, 'away_goals' => 1]);
        foreach ($prediction->markets as $market) {
            $market->update(['outcome' => 'won', 'settled_at' => now()->subDay()]);
        }

        $this->get('/history')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('History', false)
                ->count('rows.data', 4)
            );

        $this->get('/history?market=corners')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('History', false)
                ->count('rows.data', 1)
                ->where('rows.data.0.market', 'corners')
                ->where('rows.data.0.outcome', 'won')
                ->where('filters.market', 'corners')
            );

        // League filter that matches nothing.
        $this->get('/history?league=SA')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('History', false)
                ->count('rows.data', 0)
            );
    }

    public function test_pipeline_freshness_is_shared_with_every_page(): void
    {
        PipelineRun::create([
            'job_name' => 'SyncFixturesJob',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
            'status' => 'success',
        ]);

        $this->get('/accuracy')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pipeline.fixtures_as_of', fn ($value) => $value !== null)
                ->where('pipeline.predictions_as_of', null)
            );
    }
}
