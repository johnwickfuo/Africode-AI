<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\Team;
use App\Services\FootballDataCoUk\CsvStatsImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class MislabelledSeasonTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'Div,Date,Time,HomeTeam,AwayTeam,FTHG,FTAG,FTR,Referee,HS,AS,HST,AST,HF,AF,HC,AC,HY,AY,HR,AR';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Sleep::fake();
    }

    public function test_rows_from_another_century_are_rejected(): void
    {
        // Exactly the production incident: the 2627 directory answered with
        // 1926-27 First Division results, which must not be stored as the
        // 2026-2027 season.
        Http::fake([
            'www.football-data.co.uk/mmz4281/2627/E0.csv' => Http::response(
                "\u{FEFF}".self::HEADER."\n"
                .'E0,28/08/1926,15:00,Arsenal,Liverpool,2,0,H,,,,,,,,,,,,,'."\n"
                .'E0,15/08/2026,15:00,Arsenal,Chelsea,1,1,D,,,,,,,,,,,,,',
            ),
            'www.football-data.co.uk/*' => Http::response(self::HEADER),
        ]);

        $summary = app(CsvStatsImportService::class)->run(['2627']);

        $this->assertSame(1, $summary['rows_wrong_season'], '1926 row rejected');
        $this->assertSame(1, $summary['fixtures_created'], 'the genuine 2026 row still imports');

        $this->assertSame(0, Fixture::where('kickoff_utc', '<', '2000-01-01')->count());
        $this->assertSame('Chelsea', Fixture::first()->awayTeam->name);
    }

    public function test_purge_command_removes_mislabelled_fixtures_and_their_stats(): void
    {
        $arsenal = Team::where('name', 'Arsenal')->firstOrFail();
        $spurs = Team::where('name', 'Tottenham Hotspur')->firstOrFail();

        $bogus = Fixture::create([
            'league_id' => $arsenal->league_id, 'season' => '2026-2027',
            'home_team_id' => $arsenal->id, 'away_team_id' => $spurs->id,
            'kickoff_utc' => '1926-08-28 15:00:00', 'status' => Fixture::STATUS_FINISHED,
            'home_goals' => 2, 'away_goals' => 0,
        ]);
        MatchStat::create([
            'fixture_id' => $bogus->id, 'team_id' => $arsenal->id, 'is_home' => true,
            'goals' => 2, 'source' => 'fdcouk',
        ]);

        $good = Fixture::create([
            'league_id' => $arsenal->league_id, 'season' => '2026-2027',
            'home_team_id' => $arsenal->id, 'away_team_id' => $spurs->id,
            'kickoff_utc' => '2026-08-15 15:00:00', 'status' => Fixture::STATUS_SCHEDULED,
        ]);

        // A defunct club whose only fixture was the bogus one.
        $defunct = Team::create([
            'league_id' => $arsenal->league_id, 'name' => 'Bury',
            'fbref_name' => 'Bury', 'short_name' => 'BUR',
        ]);
        Fixture::create([
            'league_id' => $arsenal->league_id, 'season' => '2026-2027',
            'home_team_id' => $defunct->id, 'away_team_id' => $spurs->id,
            'kickoff_utc' => '1927-01-15 15:00:00', 'status' => Fixture::STATUS_FINISHED,
            'home_goals' => 0, 'away_goals' => 1,
        ]);

        $this->artisan('africode:purge-mislabelled-fixtures --dry-run')->assertSuccessful();
        $this->assertNotNull(Fixture::find($bogus->id), 'dry run changes nothing');

        $this->artisan('africode:purge-mislabelled-fixtures --prune-teams')->assertSuccessful();

        $this->assertNull(Fixture::find($bogus->id));
        $this->assertNotNull(Fixture::find($good->id), 'this season\'s real fixture survives');
        $this->assertSame(0, MatchStat::where('fixture_id', $bogus->id)->count(), 'stats cascade');
        $this->assertNull(Team::find($defunct->id), 'fixture-less club pruned');
        $this->assertNotNull(Team::find($arsenal->id), 'clubs with fixtures kept');
    }
}
