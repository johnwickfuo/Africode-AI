<?php

namespace Tests\Feature;

use App\Jobs\RecomputeProfilesJob;
use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\PipelineRun;
use App\Models\Referee;
use App\Models\Team;
use App\Models\TeamProfile;
use App\Services\Profiles\RecomputeProfilesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecomputeProfilesTest extends TestCase
{
    use RefreshDatabase;

    // 0.5^(1/8): weight of a match one step older than the latest.
    private const W1 = 0.9170040432;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function team(string $name): Team
    {
        return Team::where('name', $name)->firstOrFail();
    }

    private function finishedFixture(
        Team $home,
        Team $away,
        string $season,
        string $kickoff,
        array $homeStats = [],
        array $awayStats = [],
        ?Referee $referee = null,
    ): Fixture {
        $defaults = [
            'goals' => 1, 'xg' => 1.0, 'xga' => 1.0, 'shots' => 10, 'shots_on_target' => 4,
            'shots_on_target_against' => 4, 'corners_for' => 5, 'corners_against' => 5,
            'crosses' => 15, 'fouls_committed' => 10, 'fouls_drawn' => 10,
            'yellows' => 2, 'reds' => 0, 'possession' => 50.0,
        ];
        $homeStats += $defaults;
        $awayStats += $defaults;

        $fixture = Fixture::create([
            'league_id' => $home->league_id,
            'season' => $season,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'kickoff_utc' => $kickoff,
            'status' => Fixture::STATUS_FINISHED,
            'referee_id' => $referee?->id,
            'home_goals' => $homeStats['goals'],
            'away_goals' => $awayStats['goals'],
        ]);

        MatchStat::create(['fixture_id' => $fixture->id, 'team_id' => $home->id, 'is_home' => true, 'source' => 'fbref'] + $homeStats);
        MatchStat::create(['fixture_id' => $fixture->id, 'team_id' => $away->id, 'is_home' => false, 'source' => 'fbref'] + $awayStats);

        return $fixture;
    }

    public function test_averages_are_exponentially_weighted_by_recency(): void
    {
        $arsenal = $this->team('Arsenal');

        $this->finishedFixture($arsenal, $this->team('Chelsea'), '2025-2026', '2025-08-16 15:00:00', ['corners_for' => 10]);
        $this->finishedFixture($arsenal, $this->team('Fulham'), '2025-2026', '2025-08-23 15:00:00', ['corners_for' => 6]);

        app(RecomputeProfilesService::class)->run();

        $profile = TeamProfile::where('team_id', $arsenal->id)->where('season', '2025-2026')->first();

        // (6·1 + 10·0.917) / (1 + 0.917) — the newer match dominates.
        $expected = (6 + 10 * self::W1) / (1 + self::W1);
        $this->assertEqualsWithDelta($expected, $profile->corners_for_avg, 0.005);
        $this->assertSame(2, $profile->matches_played);
    }

    public function test_strengths_are_blended_ratios_against_the_league_average(): void
    {
        $arsenal = $this->team('Arsenal');
        $spurs = $this->team('Tottenham Hotspur');

        $this->finishedFixture(
            $arsenal, $spurs, '2025-2026', '2025-08-16 15:00:00',
            ['goals' => 2, 'xg' => 1.9, 'xga' => 0.7, 'yellows' => 2, 'reds' => 1],
            ['goals' => 0, 'xg' => 0.7, 'xga' => 1.9],
        );

        app(RecomputeProfilesService::class)->run();

        // Blends: Arsenal 0.7·1.9 + 0.3·2 = 1.93 for / 0.49 against;
        // Tottenham the mirror. League average (1.93 + 0.49) / 2 = 1.21.
        $arsenalProfile = TeamProfile::where('team_id', $arsenal->id)->first();
        $this->assertEqualsWithDelta(1.93 / 1.21, $arsenalProfile->attack_strength, 0.001);
        $this->assertEqualsWithDelta(0.49 / 1.21, $arsenalProfile->defence_strength, 0.001);
        $this->assertEqualsWithDelta(1.9, $arsenalProfile->xg_for_avg, 0.001);
        $this->assertEqualsWithDelta(3.0, $arsenalProfile->cards_avg, 0.001); // 2 yellows + 1 red

        $spursProfile = TeamProfile::where('team_id', $spurs->id)->first();
        $this->assertEqualsWithDelta(0.49 / 1.21, $spursProfile->attack_strength, 0.001);
        $this->assertEqualsWithDelta(1.93 / 1.21, $spursProfile->defence_strength, 0.001);
    }

    public function test_current_season_profile_includes_previous_season_with_decay(): void
    {
        $arsenal = $this->team('Arsenal');

        $this->finishedFixture($arsenal, $this->team('Chelsea'), '2024-2025', '2025-04-01 15:00:00', ['corners_for' => 10]);
        $this->finishedFixture($arsenal, $this->team('Fulham'), '2025-2026', '2025-08-16 15:00:00', ['corners_for' => 6]);

        app(RecomputeProfilesService::class)->run();

        $current = TeamProfile::where('team_id', $arsenal->id)->where('season', '2025-2026')->first();
        $expected = (6 + 10 * self::W1) / (1 + self::W1);
        $this->assertEqualsWithDelta($expected, $current->corners_for_avg, 0.005);
        $this->assertSame(1, $current->matches_played, 'only current-season matches are counted');

        $previous = TeamProfile::where('team_id', $arsenal->id)->where('season', '2024-2025')->first();
        $this->assertEqualsWithDelta(10.0, $previous->corners_for_avg, 0.001);
        $this->assertSame(1, $previous->matches_played);
    }

    public function test_home_advantage_factor_compares_home_and_away_output(): void
    {
        $arsenal = $this->team('Arsenal');
        $strong = ['goals' => 2, 'xg' => 2.0];
        $weak = ['goals' => 1, 'xg' => 1.0];

        $this->finishedFixture($arsenal, $this->team('Chelsea'), '2025-2026', '2025-08-16 15:00:00', $strong);
        $this->finishedFixture($this->team('Fulham'), $arsenal, '2025-2026', '2025-08-23 15:00:00', [], $weak);
        $this->finishedFixture($arsenal, $this->team('Everton'), '2025-2026', '2025-08-30 15:00:00', $strong);
        $this->finishedFixture($this->team('Burnley'), $arsenal, '2025-2026', '2025-09-06 15:00:00', [], $weak);

        app(RecomputeProfilesService::class)->run();

        $profile = TeamProfile::where('team_id', $arsenal->id)->first();
        // Home blend 2.0 vs away blend 1.0 (identical matches per venue).
        $this->assertEqualsWithDelta(2.0, $profile->home_advantage_factor, 0.001);
    }

    public function test_referee_profiles_average_both_teams_cards_and_fouls(): void
    {
        $referee = Referee::create(['name' => 'Michael Oliver']);

        $this->finishedFixture(
            $this->team('Arsenal'), $this->team('Chelsea'), '2025-2026', '2025-08-16 15:00:00',
            ['yellows' => 2, 'reds' => 0, 'fouls_committed' => 10],
            ['yellows' => 3, 'reds' => 1, 'fouls_committed' => 12],
            $referee,
        );
        $this->finishedFixture(
            $this->team('Fulham'), $this->team('Everton'), '2025-2026', '2025-08-23 15:00:00',
            ['yellows' => 1, 'reds' => 0, 'fouls_committed' => 8],
            ['yellows' => 1, 'reds' => 0, 'fouls_committed' => 10],
            $referee,
        );

        app(RecomputeProfilesService::class)->run();

        $referee->refresh();
        $this->assertSame(2, $referee->matches_officiated);
        $this->assertEqualsWithDelta(3.5, $referee->avg_yellows_per_match, 0.001); // (5 + 2) / 2
        $this->assertEqualsWithDelta(0.5, $referee->avg_reds_per_match, 0.001);
        $this->assertEqualsWithDelta(20.0, $referee->avg_fouls_per_match, 0.001); // (22 + 18) / 2
    }

    public function test_job_records_pipeline_run_and_command_queues(): void
    {
        RecomputeProfilesJob::dispatchSync();
        $run = PipelineRun::latest('id')->first();
        $this->assertSame('RecomputeProfilesJob', $run->job_name);
        $this->assertSame(PipelineRun::STATUS_SUCCESS, $run->status);

        Queue::fake();
        $this->artisan('africode:recompute-profiles')->assertSuccessful();
        Queue::assertPushed(RecomputeProfilesJob::class);
    }
}
