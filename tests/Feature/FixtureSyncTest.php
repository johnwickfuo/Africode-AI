<?php

namespace Tests\Feature;

use App\Jobs\SyncFixturesJob;
use App\Models\Fixture;
use App\Models\PipelineRun;
use App\Models\Referee;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class FixtureSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        config(['africode.footballdata.token' => 'test-token']);
        Sleep::fake();
    }

    private function fakeApi(array $matchesByCompetition): void
    {
        $fakes = [];
        foreach ($matchesByCompetition as $code => $matches) {
            $fakes["api.football-data.org/v4/competitions/{$code}/matches*"] = Http::response(['matches' => $matches]);
        }
        $fakes['api.football-data.org/*'] = Http::response(['matches' => []]);

        Http::fake($fakes);
    }

    private function match(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 500001,
            'utcDate' => now('UTC')->addDays(3)->setTime(14, 0)->toIso8601ZuluString(),
            'status' => 'TIMED',
            'matchday' => 21,
            'season' => ['startDate' => '2025-08-15', 'endDate' => '2026-05-24'],
            'homeTeam' => ['id' => 57, 'name' => 'Arsenal FC', 'shortName' => 'Arsenal', 'tla' => 'ARS', 'crest' => 'https://crests.football-data.org/57.png'],
            'awayTeam' => ['id' => 73, 'name' => 'Tottenham Hotspur FC', 'shortName' => 'Tottenham', 'tla' => 'TOT', 'crest' => 'https://crests.football-data.org/73.png'],
            'score' => ['fullTime' => ['home' => null, 'away' => null]],
            'referees' => [],
        ], $overrides);
    }

    public function test_sync_creates_upcoming_fixture_and_backfills_team_ids(): void
    {
        $this->fakeApi(['PL' => [$this->match()]]);

        SyncFixturesJob::dispatchSync();

        $arsenal = Team::where('name', 'Arsenal')->first();
        $spurs = Team::where('name', 'Tottenham Hotspur')->first();

        $fixture = Fixture::where('footballdata_match_id', 500001)->first();
        $this->assertNotNull($fixture);
        $this->assertSame($arsenal->id, $fixture->home_team_id);
        $this->assertSame($spurs->id, $fixture->away_team_id);
        $this->assertSame('2025-2026', $fixture->season);
        $this->assertSame(21, $fixture->matchday);
        $this->assertSame(Fixture::STATUS_SCHEDULED, $fixture->status);
        $this->assertTrue($fixture->is_derby, 'Arsenal v Spurs must be flagged as a derby');

        // Name match backfills the football-data.org id and crest.
        $this->assertSame(57, $arsenal->fresh()->footballdata_id);
        $this->assertSame(73, $spurs->fresh()->footballdata_id);
        $this->assertSame('https://crests.football-data.org/57.png', $arsenal->fresh()->logo_url);

        // One request per league, 7s throttle between consecutive requests.
        Http::assertSentCount(5);
        Sleep::assertSleptTimes(4);

        $run = PipelineRun::lastSuccessfulRun('SyncFixturesJob');
        $this->assertNotNull($run);
    }

    public function test_sync_stores_result_and_referee_for_finished_match(): void
    {
        $this->fakeApi(['PL' => [$this->match([
            'id' => 500002,
            'utcDate' => now('UTC')->subDay()->setTime(15, 0)->toIso8601ZuluString(),
            'status' => 'FINISHED',
            'homeTeam' => ['id' => 64, 'name' => 'Liverpool FC', 'shortName' => 'Liverpool', 'tla' => 'LIV', 'crest' => ''],
            'awayTeam' => ['id' => 62, 'name' => 'Everton FC', 'shortName' => 'Everton', 'tla' => 'EVE', 'crest' => ''],
            'score' => ['fullTime' => ['home' => 3, 'away' => 1]],
            'referees' => [['id' => 11605, 'name' => 'Michael Oliver', 'type' => 'REFEREE', 'nationality' => 'England']],
        ])]]);

        SyncFixturesJob::dispatchSync();

        $fixture = Fixture::where('footballdata_match_id', 500002)->first();
        $this->assertSame(Fixture::STATUS_FINISHED, $fixture->status);
        $this->assertSame(3, $fixture->home_goals);
        $this->assertSame(1, $fixture->away_goals);
        $this->assertTrue($fixture->is_derby, 'Merseyside derby must be flagged');
        $this->assertSame('Michael Oliver', $fixture->referee->name);
        $this->assertSame(1, Referee::count());
    }

    public function test_rerun_updates_existing_fixture_instead_of_duplicating(): void
    {
        // First run sees the match upcoming; second run sees it finished with
        // a score and a late-assigned referee.
        Http::fake([
            'api.football-data.org/v4/competitions/PL/matches*' => Http::sequence()
                ->push(['matches' => [$this->match()]])
                ->push(['matches' => [$this->match([
                    'status' => 'FINISHED',
                    'score' => ['fullTime' => ['home' => 2, 'away' => 2]],
                    'referees' => [['id' => 11585, 'name' => 'Anthony Taylor', 'type' => 'REFEREE', 'nationality' => 'England']],
                ])]]),
            'api.football-data.org/*' => Http::response(['matches' => []]),
        ]);

        SyncFixturesJob::dispatchSync();
        SyncFixturesJob::dispatchSync();

        $this->assertSame(1, Fixture::count());
        $fixture = Fixture::first();
        $this->assertSame(Fixture::STATUS_FINISHED, $fixture->status);
        $this->assertSame(2, $fixture->home_goals);
        $this->assertSame('Anthony Taylor', $fixture->referee->name);
    }

    public function test_awkward_api_names_resolve_to_seeded_teams(): void
    {
        $this->fakeApi([
            'SA' => [$this->match([
                'id' => 500003,
                'homeTeam' => ['id' => 108, 'name' => 'FC Internazionale Milano', 'shortName' => 'Inter', 'tla' => 'INT', 'crest' => ''],
                'awayTeam' => ['id' => 98, 'name' => 'AC Milan', 'shortName' => 'AC Milan', 'tla' => 'MIL', 'crest' => ''],
            ])],
            'PD' => [$this->match([
                'id' => 500004,
                'homeTeam' => ['id' => 80, 'name' => 'RCD Espanyol de Barcelona', 'shortName' => 'Espanyol', 'tla' => 'ESP', 'crest' => ''],
                'awayTeam' => ['id' => 90, 'name' => 'Real Betis Balompié', 'shortName' => 'Real Betis', 'tla' => 'BET', 'crest' => ''],
            ])],
            'BL1' => [$this->match([
                'id' => 500005,
                'homeTeam' => ['id' => 5, 'name' => 'FC Bayern München', 'shortName' => 'Bayern', 'tla' => 'FCB', 'crest' => ''],
                'awayTeam' => ['id' => 1, 'name' => '1. FC Köln', 'shortName' => '1. FC Köln', 'tla' => 'KOE', 'crest' => ''],
            ])],
            'FL1' => [$this->match([
                'id' => 500006,
                'homeTeam' => ['id' => 521, 'name' => 'LOSC Lille', 'shortName' => 'Lille', 'tla' => 'LIL', 'crest' => ''],
                'awayTeam' => ['id' => 546, 'name' => 'RC Strasbourg Alsace', 'shortName' => 'Strasbourg', 'tla' => 'RCSA', 'crest' => ''],
            ])],
        ]);

        SyncFixturesJob::dispatchSync();

        $expectations = [
            500003 => ['Inter Milan', 'AC Milan'],
            500004 => ['Espanyol', 'Real Betis'],
            500005 => ['Bayern Munich', '1. FC Köln'],
            500006 => ['Lille OSC', 'RC Strasbourg'],
        ];

        foreach ($expectations as $matchId => [$home, $away]) {
            $fixture = Fixture::where('footballdata_match_id', $matchId)->first();
            $this->assertNotNull($fixture, "Fixture {$matchId} should have been created");
            $this->assertSame($home, $fixture->homeTeam->name, "Home team of match {$matchId}");
            $this->assertSame($away, $fixture->awayTeam->name, "Away team of match {$matchId}");
        }

        $this->assertTrue(Fixture::where('footballdata_match_id', 500003)->first()->is_derby);
    }

    public function test_unresolvable_team_skips_match_without_failing_sync(): void
    {
        $this->fakeApi(['PL' => [
            $this->match(['id' => 500007, 'homeTeam' => ['id' => 999, 'name' => 'Wanderers Nomads FC', 'shortName' => 'Nomads', 'tla' => 'NOM', 'crest' => '']]),
            $this->match(['id' => 500008]),
        ]]);

        SyncFixturesJob::dispatchSync();

        $this->assertSame(0, Fixture::where('footballdata_match_id', 500007)->count());
        $this->assertSame(1, Fixture::where('footballdata_match_id', 500008)->count());
        $this->assertSame(PipelineRun::STATUS_SUCCESS, PipelineRun::latest('id')->first()->status);
    }

    public function test_failed_sync_is_recorded_in_pipeline_runs(): void
    {
        config(['africode.footballdata.token' => null]);

        $this->expectException(\RuntimeException::class);

        try {
            SyncFixturesJob::dispatchSync();
        } finally {
            $run = PipelineRun::latest('id')->first();
            $this->assertSame(PipelineRun::STATUS_FAILED, $run->status);
            $this->assertStringContainsString('FOOTBALLDATA_TOKEN', $run->error);
        }
    }

    public function test_sync_command_queues_the_job(): void
    {
        Queue::fake();

        $this->artisan('africode:sync-fixtures')->assertSuccessful();

        Queue::assertPushed(SyncFixturesJob::class);
    }
}
