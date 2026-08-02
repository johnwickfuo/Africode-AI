<?php

namespace Tests\Feature;

use App\Jobs\ImportCsvStatsJob;
use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\PipelineRun;
use App\Models\Referee;
use App\Models\Team;
use App\Services\FootballDataCoUk\CsvStatsImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CsvStatsImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Sleep::fake();
    }

    private const HEADER = 'Div,Date,Time,HomeTeam,AwayTeam,FTHG,FTAG,FTR,Referee,HS,AS,HST,AST,HF,AF,HC,AC,HY,AY,HR,AR';

    private function fakeCsv(array $files): void
    {
        $fakes = [];
        foreach ($files as $path => $rows) {
            $fakes["www.football-data.co.uk/mmz4281/{$path}.csv"] = Http::response(
                "\u{FEFF}".self::HEADER."\n".implode("\n", $rows)
            );
        }
        $fakes['www.football-data.co.uk/*'] = Http::response(self::HEADER);

        Http::fake($fakes);
    }

    public function test_imports_fixture_stats_and_referee_from_csv(): void
    {
        $this->fakeCsv(['2526/E0' => [
            'E0,15/08/2025,20:00,Liverpool,Bournemouth,4,2,H,A Taylor,19,10,10,3,7,10,6,7,1,2,0,0',
        ]]);

        $summary = app(CsvStatsImportService::class)->run(['2526']);

        $this->assertSame(1, $summary['fixtures_created']);
        $this->assertSame(2, $summary['stats_rows']);

        $fixture = Fixture::first();
        $this->assertSame('2025-2026', $fixture->season);
        $this->assertSame('Liverpool', $fixture->homeTeam->name);
        $this->assertSame('AFC Bournemouth', $fixture->awayTeam->name);
        $this->assertSame(4, $fixture->home_goals);
        $this->assertSame('A Taylor', $fixture->referee->name);
        $this->assertSame('2025-08-15 20:00', $fixture->kickoff_utc->format('Y-m-d H:i'));

        $home = MatchStat::where('team_id', $fixture->home_team_id)->first();
        $this->assertSame(19, $home->shots);
        $this->assertSame(10, $home->shots_on_target);
        $this->assertSame(3, $home->shots_on_target_against);
        $this->assertSame(6, $home->corners_for);
        $this->assertSame(7, $home->corners_against);
        $this->assertSame(7, $home->fouls_committed);
        $this->assertSame(10, $home->fouls_drawn);
        $this->assertSame(1, $home->yellows);
        $this->assertSame('fdcouk', $home->source);
        $this->assertNull($home->xg);
    }

    public function test_awkward_csv_names_resolve_via_aliases_and_matching(): void
    {
        $this->fakeCsv([
            '2526/E0' => ["E0,16/08/2025,15:00,Man United,Nott'm Forest,1,1,D,M Oliver,10,8,4,3,9,11,5,4,2,3,0,0"],
            '2526/SP1' => ['SP1,17/08/2025,19:30,Ath Madrid,Sociedad,2,0,H,,12,6,5,2,14,12,7,3,3,2,0,1'],
            '2526/D1' => ["D1,17/08/2025,17:30,M'gladbach,Ein Frankfurt,0,2,A,,9,13,2,6,10,8,4,6,1,1,0,0"],
        ]);

        app(CsvStatsImportService::class)->run(['2526']);

        $names = Fixture::with('homeTeam', 'awayTeam')->get()
            ->flatMap(fn ($f) => [$f->homeTeam->name, $f->awayTeam->name]);

        $this->assertContains('Manchester United', $names);
        $this->assertContains('Nottingham Forest', $names);
        $this->assertContains('Atlético Madrid', $names);
        $this->assertContains('Real Sociedad', $names);
        $this->assertContains('Borussia Mönchengladbach', $names);
        $this->assertContains('Eintracht Frankfurt', $names);
        // Nothing got auto-created — every name resolved to a seeded club.
        $this->assertSame(0, Team::whereNotIn('name', $names)->where('created_at', '>', now()->subMinute())
            ->whereNull('fbref_name')->count());
    }

    public function test_relegated_historical_team_is_auto_created(): void
    {
        $this->fakeCsv(['2324/E0' => [
            'E0,01/09/2023,15:00,Luton,Arsenal,0,2,A,,5,18,1,8,12,6,2,9,2,1,0,0',
        ]]);

        $summary = app(CsvStatsImportService::class)->run(['2324']);

        $this->assertSame(1, $summary['teams_created']);
        $this->assertNotNull(Team::where('name', 'Luton')->first());
        $this->assertSame('2023-2024', Fixture::first()->season);
    }

    public function test_never_overwrites_richer_fbref_rows(): void
    {
        $arsenal = Team::where('name', 'Arsenal')->first();
        $chelsea = Team::where('name', 'Chelsea')->first();
        $fixture = Fixture::create([
            'league_id' => $arsenal->league_id, 'season' => '2025-2026',
            'home_team_id' => $arsenal->id, 'away_team_id' => $chelsea->id,
            'kickoff_utc' => '2025-08-16 15:00:00', 'status' => 'finished',
            'home_goals' => 2, 'away_goals' => 1,
        ]);
        MatchStat::create([
            'fixture_id' => $fixture->id, 'team_id' => $arsenal->id, 'is_home' => true,
            'goals' => 2, 'xg' => 1.9, 'corners_for' => 6, 'source' => 'fbref',
        ]);

        $this->fakeCsv(['2526/E0' => [
            'E0,16/08/2025,15:00,Arsenal,Chelsea,2,1,H,,15,9,7,4,8,10,9,3,1,2,0,0',
        ]]);

        $summary = app(CsvStatsImportService::class)->run(['2526']);

        $arsenalStats = MatchStat::where('team_id', $arsenal->id)->first();
        $this->assertSame('fbref', $arsenalStats->source);
        $this->assertSame(1.9, $arsenalStats->xg, 'FBref xG must survive');
        $this->assertSame(6, $arsenalStats->corners_for, 'FBref corners must survive');
        // Chelsea had no fbref row, so the CSV filled it.
        $this->assertSame('fdcouk', MatchStat::where('team_id', $chelsea->id)->first()->source);
    }

    public function test_fills_gaps_a_partial_fbref_scrape_left_behind(): void
    {
        // Production case: FBref's per-stat tables were blocked, so the row
        // carried only goals and xG from the schedule. The CSV has corners,
        // cards, fouls and shots — it must fill those without touching xG.
        $arsenal = Team::where('name', 'Arsenal')->first();
        $chelsea = Team::where('name', 'Chelsea')->first();
        $fixture = Fixture::create([
            'league_id' => $arsenal->league_id, 'season' => '2025-2026',
            'home_team_id' => $arsenal->id, 'away_team_id' => $chelsea->id,
            'kickoff_utc' => '2025-08-16 15:00:00', 'status' => 'finished',
            'home_goals' => 2, 'away_goals' => 1,
        ]);
        MatchStat::create([
            'fixture_id' => $fixture->id, 'team_id' => $arsenal->id, 'is_home' => true,
            'goals' => 2, 'xg' => 1.9, 'source' => 'fbref',
        ]);

        $this->fakeCsv(['2526/E0' => [
            'E0,16/08/2025,15:00,Arsenal,Chelsea,2,1,H,,15,9,7,4,8,10,9,3,1,2,0,0',
        ]]);

        $summary = app(CsvStatsImportService::class)->run(['2526']);

        $this->assertGreaterThan(0, $summary['stats_gaps_filled']);

        $row = MatchStat::where('team_id', $arsenal->id)->first();
        $this->assertSame('fbref', $row->source, 'ownership stays with the richer source');
        $this->assertSame(1.9, $row->xg, 'FBref xG untouched');
        $this->assertSame(9, $row->corners_for, 'corners filled from the CSV');
        $this->assertSame(7, $row->shots_on_target, 'shots on target filled');
        $this->assertSame(8, $row->fouls_committed, 'fouls filled');
        $this->assertSame(1, $row->yellows, 'cards filled');
    }

    public function test_referee_initial_matches_existing_full_name(): void
    {
        Referee::create(['name' => 'Anthony Taylor']);

        $this->fakeCsv(['2526/E0' => [
            'E0,15/08/2025,20:00,Liverpool,Bournemouth,4,2,H,A Taylor,19,10,10,3,7,10,6,7,1,2,0,0',
        ]]);

        app(CsvStatsImportService::class)->run(['2526']);

        $this->assertSame(1, Referee::count(), 'abbreviated name must not duplicate the referee');
        $this->assertSame('Anthony Taylor', Fixture::first()->referee->name);
    }

    public function test_failed_download_is_nonfatal_and_job_wiring_works(): void
    {
        Http::fake(['www.football-data.co.uk/*' => Http::response('', 404)]);

        ImportCsvStatsJob::dispatchSync();

        $run = PipelineRun::latest('id')->first();
        $this->assertSame('ImportCsvStatsJob', $run->job_name);
        $this->assertSame(PipelineRun::STATUS_SUCCESS, $run->status, 'missing files are logged, not fatal');
        $this->assertSame(0, Fixture::count());

        Queue::fake();
        $this->artisan('africode:import-csv-stats')->assertSuccessful();
        Queue::assertPushed(ImportCsvStatsJob::class);
    }
}
