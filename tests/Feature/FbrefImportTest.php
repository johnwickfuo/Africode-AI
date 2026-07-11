<?php

namespace Tests\Feature;

use App\Jobs\ImportFbrefDataJob;
use App\Jobs\ScrapeFbrefJob;
use App\Models\Fixture;
use App\Models\League;
use App\Models\MatchStat;
use App\Models\PipelineRun;
use App\Models\Referee;
use App\Models\Team;
use App\Services\Fbref\ImportFbrefDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FbrefImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dataPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->dataPath = sys_get_temp_dir().'/fbref_test_'.uniqid().'.json';
        config(['africode.fbref.output_path' => $this->dataPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dataPath);

        parent::tearDown();
    }

    private function writeData(array $matches): void
    {
        file_put_contents($this->dataPath, json_encode([
            'generated_at' => now()->toIso8601String(),
            'seasons' => ['2324', '2425', '2526'],
            'failed_stat_tables' => [],
            'match_count' => count($matches),
            'matches' => $matches,
        ]));
    }

    private function fbrefMatch(array $overrides = []): array
    {
        $stats = [
            'goals' => 2, 'xg' => 1.9, 'xga' => 0.7, 'shots' => 15, 'shots_on_target' => 6,
            'shots_on_target_against' => 2, 'corners_for' => 8, 'corners_against' => 3,
            'crosses' => 22, 'fouls_committed' => 11, 'fouls_drawn' => 9,
            'yellows' => 2, 'reds' => 0, 'possession' => 58.0,
        ];

        return array_replace_recursive([
            'league' => 'ENG-Premier League',
            'season' => '2526',
            'game' => '2025-08-16 Arsenal-Tottenham',
            'date' => '2025-08-16',
            'kickoff' => '2025-08-16 17:30',
            'matchday' => 1,
            'referee' => 'Michael Oliver',
            'home_team' => 'Arsenal',
            'away_team' => 'Tottenham',
            'home_goals' => 2,
            'away_goals' => 0,
            'home' => $stats,
            'away' => array_merge($stats, ['goals' => 0, 'xg' => 0.7, 'xga' => 1.9, 'possession' => 42.0]),
        ], $overrides);
    }

    public function test_import_creates_fixture_stats_and_referee(): void
    {
        $this->writeData([$this->fbrefMatch()]);

        $summary = app(ImportFbrefDataService::class)->run();

        $this->assertSame(1, $summary['fixtures_created']);
        $this->assertSame(2, $summary['stats_rows']);

        $fixture = Fixture::first();
        $this->assertSame('2025-2026', $fixture->season);
        $this->assertSame(Fixture::STATUS_FINISHED, $fixture->status);
        $this->assertSame(2, $fixture->home_goals);
        $this->assertSame(0, $fixture->away_goals);
        $this->assertSame(1, $fixture->matchday);
        $this->assertTrue($fixture->is_derby);
        $this->assertSame('Michael Oliver', $fixture->referee->name);
        $this->assertSame('Arsenal', $fixture->homeTeam->name);
        $this->assertSame('Tottenham Hotspur', $fixture->awayTeam->name);

        $homeStats = MatchStat::where('team_id', $fixture->home_team_id)->first();
        $this->assertTrue($homeStats->is_home);
        $this->assertSame(8, $homeStats->corners_for);
        $this->assertSame(3, $homeStats->corners_against);
        $this->assertSame(1.9, $homeStats->xg);
        $this->assertSame(58.0, $homeStats->possession);
        $this->assertSame('fbref', $homeStats->source);
    }

    public function test_import_parses_real_fbref_kickoff_format(): void
    {
        // Exact shape from a real scrape: pandas midnight-stamped date glued
        // to FBref's "local (venue)" time — crashed the importer in prod.
        $this->writeData([$this->fbrefMatch([
            'date' => '2023-08-12 00:00:00',
            'kickoff' => '2023-08-12 00:00:00 12:30 (13:30)',
            'season' => '2324',
        ])]);

        $summary = app(ImportFbrefDataService::class)->run();

        $this->assertSame(1, $summary['fixtures_created']);
        $this->assertSame('2023-08-12 12:30:00', Fixture::first()->kickoff_utc->toDateTimeString());
    }

    public function test_import_adopts_fbref_name_of_sync_created_team_instead_of_duplicating(): void
    {
        // A promoted side created by the fixture sync with a guessed fbref_name.
        $league = League::where('code', 'PL')->first();
        $norwich = \App\Models\Team::create([
            'league_id' => $league->id, 'name' => 'Norwich City',
            'fbref_name' => 'Norwich', 'short_name' => 'NOR',
        ]);

        // FBref's real squad name is "Norwich City".
        $this->writeData([$this->fbrefMatch([
            'game' => '2026-08-20 Norwich City-Arsenal',
            'date' => '2026-08-20',
            'kickoff' => '2026-08-20 15:00',
            'home_team' => 'Norwich City',
        ])]);

        $summary = app(ImportFbrefDataService::class)->run();

        $this->assertSame(0, $summary['teams_created'], 'must reuse the sync-created team');
        $this->assertSame('Norwich City', $norwich->fresh()->fbref_name, 'real FBref name adopted');
        $this->assertSame($norwich->id, Fixture::first()->home_team_id);
    }

    public function test_import_auto_creates_teams_from_historical_seasons(): void
    {
        $this->writeData([$this->fbrefMatch([
            'season' => '2324',
            'game' => '2023-09-01 Luton Town-Arsenal',
            'date' => '2023-09-01',
            'kickoff' => '2023-09-01 15:00',
            'home_team' => 'Luton Town',
            'referee' => null,
        ])]);

        $summary = app(ImportFbrefDataService::class)->run();

        $this->assertSame(1, $summary['teams_created']);

        $luton = Team::where('fbref_name', 'Luton Town')->first();
        $this->assertNotNull($luton);
        $this->assertSame('LUT', $luton->short_name);
        $this->assertSame(League::where('code', 'PL')->first()->id, $luton->league_id);

        $fixture = Fixture::first();
        $this->assertSame('2023-2024', $fixture->season);
        $this->assertNull($fixture->referee_id);
        $this->assertFalse($fixture->is_derby);
    }

    public function test_import_updates_existing_footballdata_fixture_without_duplicating(): void
    {
        $arsenal = Team::where('name', 'Arsenal')->first();
        $spurs = Team::where('name', 'Tottenham Hotspur')->first();
        $existing = Fixture::create([
            'league_id' => $arsenal->league_id,
            'season' => '2025-2026',
            'matchday' => 1,
            'home_team_id' => $arsenal->id,
            'away_team_id' => $spurs->id,
            'kickoff_utc' => '2025-08-16 16:30:00', // authoritative UTC time from football-data
            'status' => Fixture::STATUS_SCHEDULED,
            'footballdata_match_id' => 500001,
            'is_derby' => true,
        ]);

        $this->writeData([$this->fbrefMatch()]);
        app(ImportFbrefDataService::class)->run();

        $this->assertSame(1, Fixture::count());
        $fixture = $existing->fresh();
        $this->assertSame(500001, $fixture->footballdata_match_id);
        $this->assertSame('2025-08-16 16:30:00', $fixture->kickoff_utc->toDateTimeString(), 'football-data kickoff must be kept');
        $this->assertSame(Fixture::STATUS_FINISHED, $fixture->status);
        $this->assertSame(2, $fixture->home_goals);
        $this->assertSame(2, MatchStat::count());
    }

    public function test_import_is_idempotent(): void
    {
        $this->writeData([$this->fbrefMatch()]);

        app(ImportFbrefDataService::class)->run();
        $summary = app(ImportFbrefDataService::class)->run();

        $this->assertSame(0, $summary['fixtures_created']);
        $this->assertSame(0, $summary['fixtures_updated']);
        $this->assertSame(1, Fixture::count());
        $this->assertSame(2, MatchStat::count());
        $this->assertSame(1, Referee::count());
    }

    public function test_unknown_league_is_skipped_without_failing(): void
    {
        $this->writeData([
            $this->fbrefMatch(['league' => 'NED-Eredivisie']),
            $this->fbrefMatch(),
        ]);

        $summary = app(ImportFbrefDataService::class)->run();

        $this->assertSame(1, $summary['matches_skipped']);
        $this->assertSame(1, $summary['fixtures_created']);
    }

    public function test_missing_data_file_fails_and_is_recorded(): void
    {
        config(['africode.fbref.output_path' => '/nonexistent/fbref_latest.json']);

        $this->expectException(\RuntimeException::class);

        try {
            ImportFbrefDataJob::dispatchSync();
        } finally {
            $run = PipelineRun::latest('id')->first();
            $this->assertSame(PipelineRun::STATUS_FAILED, $run->status);
            $this->assertSame('ImportFbrefDataJob', $run->job_name);
        }
    }
}
