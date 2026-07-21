<?php

namespace Tests\Feature;

use App\Jobs\ScrapeUnderstatJob;
use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\PipelineRun;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\Team;
use App\Services\Understat\ImportUnderstatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UnderstatImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dataPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->dataPath = sys_get_temp_dir().'/understat_'.uniqid().'.json';
        config(['africode.understat.output_path' => $this->dataPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dataPath);

        parent::tearDown();
    }

    private function team(string $name): Team
    {
        return Team::where('name', $name)->firstOrFail();
    }

    private function fixtureFor(Team $home, Team $away, string $kickoff = '2025-08-16 15:00:00'): Fixture
    {
        return Fixture::create([
            'league_id' => $home->league_id, 'season' => '2025-2026',
            'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'kickoff_utc' => $kickoff, 'status' => Fixture::STATUS_FINISHED,
            'home_goals' => 2, 'away_goals' => 0,
        ]);
    }

    private function writeData(array $matches): void
    {
        file_put_contents($this->dataPath, json_encode([
            'generated_at' => now()->toIso8601String(),
            'match_count' => count($matches),
            'rosters_missing' => 0,
            'matches' => $matches,
        ]));
    }

    private function understatMatch(array $overrides = []): array
    {
        return array_replace($this->baseMatch(), $overrides);
    }

    private function baseMatch(): array
    {
        return [
            'league' => 'PL', 'season' => '2526', 'understat_id' => '28778',
            'datetime' => '2025-08-16 14:00:00',
            'home_team' => 'Arsenal', 'away_team' => 'Tottenham',
            'home_goals' => 2, 'away_goals' => 0,
            'home_xg' => 1.93, 'away_xg' => 0.49,
            'players' => [
                ['name' => 'Bukayo Saka', 'side' => 'home', 'position' => 'AMR', 'minutes' => 90,
                    'goals' => 1, 'assists' => 1, 'shots' => 4, 'xg' => 0.8, 'xa' => 0.4, 'yellows' => 0, 'reds' => 0],
                ['name' => 'Cristian Romero', 'side' => 'away', 'position' => 'DC', 'minutes' => 90,
                    'goals' => 0, 'assists' => 0, 'shots' => 1, 'xg' => 0.1, 'xa' => 0.0, 'yellows' => 1, 'reds' => 1],
            ],
        ];
    }

    public function test_imports_player_rows_and_enriches_team_xg(): void
    {
        $arsenal = $this->team('Arsenal');
        $spurs = $this->team('Tottenham Hotspur');
        $fixture = $this->fixtureFor($arsenal, $spurs);

        // CSV-sourced stat rows (no xG) that must get enriched.
        foreach ([[$arsenal, true], [$spurs, false]] as [$team, $isHome]) {
            MatchStat::create([
                'fixture_id' => $fixture->id, 'team_id' => $team->id, 'is_home' => $isHome,
                'goals' => $isHome ? 2 : 0, 'corners_for' => 6, 'source' => 'fdcouk',
            ]);
        }

        $this->writeData([$this->understatMatch()]);
        $summary = app(ImportUnderstatService::class)->run($this->dataPath);

        $this->assertSame(1, $summary['matches_imported']);
        $this->assertSame(2, $summary['player_rows']);
        $this->assertSame(2, $summary['xg_enriched']);

        $saka = Player::where('name', 'Bukayo Saka')->first();
        $this->assertSame($arsenal->id, $saka->team_id);
        $row = PlayerMatchStat::where('player_id', $saka->id)->first();
        $this->assertSame(1, $row->goals);
        $this->assertSame(4, $row->shots);
        $this->assertSame(0.8, $row->xg);
        $this->assertNull($row->shots_on_target, 'understat has no SoT');

        $homeStat = MatchStat::where('team_id', $arsenal->id)->first();
        $this->assertSame(1.93, $homeStat->xg);
        $this->assertSame(0.49, $homeStat->xga);
        $this->assertSame(6, $homeStat->corners_for, 'CSV stats survive enrichment');
    }

    public function test_never_degrades_fbref_data(): void
    {
        $arsenal = $this->team('Arsenal');
        $spurs = $this->team('Tottenham Hotspur');
        $fixture = $this->fixtureFor($arsenal, $spurs);

        // FBref team row with its own xG — must keep it.
        MatchStat::create([
            'fixture_id' => $fixture->id, 'team_id' => $arsenal->id, 'is_home' => true,
            'goals' => 2, 'xg' => 1.85, 'source' => 'fbref',
        ]);
        // FBref player row with shots_on_target — must keep it.
        $saka = Player::create(['team_id' => $arsenal->id, 'name' => 'Bukayo Saka',
            'nationality' => 'ENG', 'last_seen_at' => now()]);
        PlayerMatchStat::create([
            'player_id' => $saka->id, 'fixture_id' => $fixture->id, 'team_id' => $arsenal->id,
            'minutes' => 90, 'goals' => 1, 'shots_on_target' => 2,
        ]);

        $this->writeData([$this->understatMatch()]);
        app(ImportUnderstatService::class)->run($this->dataPath);

        $this->assertSame(1.85, MatchStat::where('team_id', $arsenal->id)->first()->xg, 'FBref team xG kept');
        $row = PlayerMatchStat::where('player_id', $saka->id)->first();
        $this->assertSame(2, $row->shots_on_target, 'FBref SoT kept');
        $this->assertSame(0.8, $row->xg, 'understat fields still updated');
        $this->assertSame(1, Player::where('name', 'Bukayo Saka')->count(),
            'name-only lookup must reuse the FBref-created player, not duplicate it');
    }

    public function test_fills_xg_gap_on_fbref_row_without_xg(): void
    {
        $arsenal = $this->team('Arsenal');
        $spurs = $this->team('Tottenham Hotspur');
        $fixture = $this->fixtureFor($arsenal, $spurs);

        // FBref row whose scrape carried no xG — the gap Understat must fill.
        MatchStat::create([
            'fixture_id' => $fixture->id, 'team_id' => $arsenal->id, 'is_home' => true,
            'goals' => 2, 'corners_for' => 8, 'source' => 'fbref',
        ]);

        $this->writeData([$this->understatMatch(['players' => []])]);
        $summary = app(ImportUnderstatService::class)->run($this->dataPath);

        $row = MatchStat::where('team_id', $arsenal->id)->first();
        $this->assertSame(1.93, $row->xg, 'empty FBref xG enriched from Understat');
        $this->assertSame(8, $row->corners_for, 'FBref stats untouched');
        $this->assertSame('fbref', $row->source);
        $this->assertSame(1, $summary['xg_enriched']);
    }

    public function test_leipzig_alias_and_unknown_fixture_skip(): void
    {
        $leipzig = $this->team('RB Leipzig');
        $bayern = $this->team('Bayern Munich');
        $this->fixtureFor($leipzig, $bayern);

        $this->writeData([
            $this->understatMatch([
                'league' => 'BL1',
                'home_team' => 'RasenBallsport Leipzig', 'away_team' => 'Bayern Munich',
                'players' => [],
            ]),
            // No fixture exists for this one -> skipped, not fatal.
            $this->understatMatch(['home_team' => 'Chelsea', 'away_team' => 'Fulham', 'players' => []]),
        ]);

        $summary = app(ImportUnderstatService::class)->run($this->dataPath);

        $this->assertSame(1, $summary['matches_imported']);
        $this->assertSame(1, $summary['matches_skipped']);
    }

    public function test_transfer_guard_keeps_newest_team(): void
    {
        $arsenal = $this->team('Arsenal');
        $spurs = $this->team('Tottenham Hotspur');
        $chelsea = $this->team('Chelsea');
        $late = $this->fixtureFor($chelsea, $spurs, '2026-02-01 15:00:00');
        $early = $this->fixtureFor($arsenal, $spurs, '2025-09-01 15:00:00');

        // Newest match first (as the scraper emits), older one after.
        $this->writeData([
            $this->understatMatch([
                'home_team' => 'Chelsea', 'datetime' => '2026-02-01 14:00:00',
                'players' => [['name' => 'Bukayo Saka', 'side' => 'home', 'position' => 'AMR',
                    'minutes' => 90, 'goals' => 0, 'assists' => 0, 'shots' => 2, 'xg' => 0.3,
                    'xa' => 0.1, 'yellows' => 0, 'reds' => 0]],
            ]),
            $this->understatMatch([
                'players' => [['name' => 'Bukayo Saka', 'side' => 'home', 'position' => 'AMR',
                    'minutes' => 90, 'goals' => 1, 'assists' => 0, 'shots' => 3, 'xg' => 0.5,
                    'xa' => 0.2, 'yellows' => 0, 'reds' => 0]],
            ]),
        ]);

        app(ImportUnderstatService::class)->run($this->dataPath);

        $saka = Player::where('name', 'Bukayo Saka')->first();
        $this->assertSame($chelsea->id, $saka->team_id, 'current team = newest match side');
        $this->assertSame($arsenal->id, PlayerMatchStat::where('fixture_id', $early->id)->first()->team_id);
        $this->assertSame($chelsea->id, PlayerMatchStat::where('fixture_id', $late->id)->first()->team_id);
    }

    public function test_job_runs_stub_script_and_records_pipeline(): void
    {
        $dir = sys_get_temp_dir().'/us_stub_'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/stub.py', <<<'PY'
            import argparse, json
            p = argparse.ArgumentParser()
            p.add_argument("--output", required=True)
            p.add_argument("--seasons", nargs="+")
            p.add_argument("--max-match-fetches")
            a = p.parse_args()
            with open(a.output, "w") as f:
                json.dump({"matches": [], "match_count": 0, "rosters_missing": 0}, f)
            PY);
        config([
            'africode.understat.script_path' => $dir.'/stub.py',
            'africode.understat.output_path' => $dir.'/out.json',
            'africode.understat.timeout_seconds' => 30,
        ]);

        ScrapeUnderstatJob::dispatchSync(50);

        $this->assertSame(PipelineRun::STATUS_SUCCESS, PipelineRun::latest('id')->first()->status);
        $this->assertFileExists($dir.'/out.json');
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);

        Queue::fake();
        $this->artisan('africode:scrape-understat')->assertSuccessful();
        Queue::assertPushed(ScrapeUnderstatJob::class);
    }
}
