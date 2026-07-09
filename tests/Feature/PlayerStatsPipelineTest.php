<?php

namespace Tests\Feature;

use App\Jobs\ScrapePlayerStatsJob;
use App\Models\Fixture;
use App\Models\PipelineRun;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\PlayerScrapeProgress;
use App\Models\Team;
use App\Services\Fbref\ImportPlayerStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerStatsPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->dir = sys_get_temp_dir().'/players_'.uniqid();
        mkdir($this->dir);
        config([
            'africode.fbref.player_output_path' => $this->dir.'/players.json',
            'africode.fbref.player_scrape_timeout_seconds' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function team(string $name): Team
    {
        return Team::where('name', $name)->firstOrFail();
    }

    private function finishedFixture(string $gameId, Team $home, Team $away, string $kickoff): Fixture
    {
        return Fixture::create([
            'league_id' => $home->league_id,
            'season' => '2025-2026',
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'kickoff_utc' => $kickoff,
            'status' => Fixture::STATUS_FINISHED,
            'fbref_game_id' => $gameId,
            'home_goals' => 2,
            'away_goals' => 0,
        ]);
    }

    private function sakaRow(array $overrides = []): array
    {
        return $overrides + [
            'player' => 'Bukayo Saka', 'team' => 'Arsenal', 'nationality' => 'ENG', 'position' => 'RW',
            'minutes' => 90, 'goals' => 1, 'assists' => 1, 'shots' => 4, 'shots_on_target' => 2,
            'yellows' => 0, 'reds' => 0, 'xg' => 0.8, 'xa' => 0.4,
        ];
    }

    private function writePlayersJson(array $matches, array $failed = []): string
    {
        $path = config('africode.fbref.player_output_path');
        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'match_count' => count($matches),
            'matches' => $matches,
            'failed_game_ids' => $failed,
        ]));

        return $path;
    }

    public function test_import_creates_players_and_match_stats(): void
    {
        $fixture = $this->finishedFixture('aaaa1111', $this->team('Arsenal'), $this->team('Tottenham Hotspur'), '2025-08-16 16:30:00');

        $this->writePlayersJson([[
            'game_id' => 'aaaa1111',
            'players' => [
                $this->sakaRow(),
                ['player' => 'Cristian Romero', 'team' => 'Tottenham', 'nationality' => 'ARG', 'position' => 'CB',
                    'minutes' => 90, 'goals' => 0, 'assists' => 0, 'shots' => 1, 'shots_on_target' => 0,
                    'yellows' => 1, 'reds' => 1, 'xg' => 0.1, 'xa' => 0.0],
            ],
        ]]);

        $result = app(ImportPlayerStatsService::class)->run();

        $this->assertSame(['aaaa1111'], $result['imported_game_ids']);
        $this->assertSame(2, $result['players_created']);

        $saka = Player::where('name', 'Bukayo Saka')->first();
        $this->assertSame($this->team('Arsenal')->id, $saka->team_id);
        $this->assertSame('RW', $saka->position);
        $this->assertSame('ENG', $saka->nationality);

        $stat = PlayerMatchStat::where('player_id', $saka->id)->first();
        $this->assertSame($fixture->id, $stat->fixture_id);
        $this->assertSame($this->team('Arsenal')->id, $stat->team_id);
        $this->assertSame(1, $stat->goals);
        $this->assertSame(0.8, $stat->xg);

        // Romero played for Spurs (fbref_name "Tottenham").
        $romero = Player::where('name', 'Cristian Romero')->first();
        $this->assertSame($this->team('Tottenham Hotspur')->id, $romero->team_id);
    }

    public function test_transfers_update_current_team_without_touching_history(): void
    {
        $arsenal = $this->team('Arsenal');
        $chelsea = $this->team('Chelsea');
        $spurs = $this->team('Tottenham Hotspur');

        $early = $this->finishedFixture('early111', $arsenal, $spurs, '2025-09-01 15:00:00');
        $late = $this->finishedFixture('late2222', $chelsea, $spurs, '2026-02-01 15:00:00');

        // Backfill runs newest-first: the February match (for Chelsea)
        // arrives BEFORE the September match (for Arsenal).
        $this->writePlayersJson([[
            'game_id' => 'late2222',
            'players' => [$this->sakaRow(['team' => 'Chelsea'])],
        ]]);
        app(ImportPlayerStatsService::class)->run();

        $this->writePlayersJson([[
            'game_id' => 'early111',
            'players' => [$this->sakaRow()],
        ]]);
        app(ImportPlayerStatsService::class)->run();

        $saka = Player::where('name', 'Bukayo Saka')->first();
        // Current team stays the newest club even though the older match
        // was imported second.
        $this->assertSame($chelsea->id, $saka->team_id);

        // Per-match history keeps the team he actually played for.
        $this->assertSame($arsenal->id, PlayerMatchStat::where('fixture_id', $early->id)->first()->team_id);
        $this->assertSame($chelsea->id, PlayerMatchStat::where('fixture_id', $late->id)->first()->team_id);
        $this->assertSame(1, Player::count(), 'transfer must not duplicate the player');
    }

    public function test_import_is_idempotent_and_flags_unknown_fixtures(): void
    {
        $this->finishedFixture('aaaa1111', $this->team('Arsenal'), $this->team('Tottenham Hotspur'), '2025-08-16 16:30:00');

        $this->writePlayersJson(
            [
                ['game_id' => 'aaaa1111', 'players' => [$this->sakaRow()]],
                ['game_id' => 'ghost999', 'players' => [$this->sakaRow()]],
            ],
            failed: ['broke404'],
        );

        app(ImportPlayerStatsService::class)->run();
        $result = app(ImportPlayerStatsService::class)->run();

        $this->assertSame(1, Player::count());
        $this->assertSame(1, PlayerMatchStat::count());
        $this->assertArrayHasKey('ghost999', $result['failed']);
        $this->assertArrayHasKey('broke404', $result['failed']);
    }

    /**
     * Stub scraper honouring the real CLI: emits stats for every requested
     * id except ones starting with "fail".
     */
    private function useStubScraper(): void
    {
        $path = $this->dir.'/stub_players.py';
        file_put_contents($path, <<<'PY'
            import argparse, json
            p = argparse.ArgumentParser()
            p.add_argument("--output", required=True)
            p.add_argument("--seasons", nargs="+")
            p.add_argument("--match-ids", nargs="+", required=True)
            a = p.parse_args()
            matches, failed = [], []
            for mid in a.match_ids:
                if mid.startswith("fail"):
                    failed.append(mid)
                    continue
                matches.append({"game_id": mid, "players": [{
                    "player": "Player " + mid, "team": "Arsenal", "nationality": "ENG",
                    "position": "FW", "minutes": 90, "goals": 1, "assists": 0, "shots": 2,
                    "shots_on_target": 1, "yellows": 0, "reds": 0, "xg": 0.5, "xa": 0.1}]})
            with open(a.output, "w") as f:
                json.dump({"matches": matches, "failed_game_ids": failed}, f)
            PY);
        config(['africode.fbref.player_script_path' => $path]);
    }

    public function test_backfill_is_resumable_in_batches_newest_first(): void
    {
        $this->useStubScraper();
        $arsenal = $this->team('Arsenal');
        $spurs = $this->team('Tottenham Hotspur');
        $chelsea = $this->team('Chelsea');

        $old = $this->finishedFixture('old00001', $arsenal, $spurs, '2024-05-01 15:00:00');
        $mid = $this->finishedFixture('mid00001', $chelsea, $arsenal, '2025-01-01 15:00:00');
        $new = $this->finishedFixture('new00001', $arsenal, $chelsea, '2025-08-16 15:00:00');

        // Night 1: batch of 2 -> the two newest fixtures.
        ScrapePlayerStatsJob::dispatchSync(2);

        $this->assertSame(3, PlayerScrapeProgress::count(), 'discovery enqueued all finished fixtures');
        $this->assertSame('done', PlayerScrapeProgress::where('fixture_id', $new->id)->first()->status);
        $this->assertSame('done', PlayerScrapeProgress::where('fixture_id', $mid->id)->first()->status);
        $this->assertSame('pending', PlayerScrapeProgress::where('fixture_id', $old->id)->first()->status);

        // Night 2: continues where it left off.
        ScrapePlayerStatsJob::dispatchSync(2);
        $this->assertSame('done', PlayerScrapeProgress::where('fixture_id', $old->id)->first()->status);
        $this->assertSame(3, PlayerMatchStat::count());

        // Night 3: nothing left — reports completion, still succeeds.
        ScrapePlayerStatsJob::dispatchSync(2);
        $this->assertSame(PipelineRun::STATUS_SUCCESS, PipelineRun::latest('id')->first()->status);

        // A newly finished fixture joins the queue on the next night.
        // (Involves Arsenal because the stub scraper emits Arsenal players.)
        $this->finishedFixture('fresh001', $arsenal, $this->team('Burnley'), '2025-08-23 15:00:00');
        ScrapePlayerStatsJob::dispatchSync(2);
        $this->assertSame(4, PlayerScrapeProgress::where('status', 'done')->count());
    }

    public function test_failed_matches_accumulate_attempts_and_stop_retrying(): void
    {
        $this->useStubScraper();
        $fixture = $this->finishedFixture('fail0001', $this->team('Arsenal'), $this->team('Chelsea'), '2025-08-16 15:00:00');

        foreach ([1, 2, 3] as $attempt) {
            ScrapePlayerStatsJob::dispatchSync(5);
            $progress = PlayerScrapeProgress::where('fixture_id', $fixture->id)->first();
            $this->assertSame('failed', $progress->status);
            $this->assertSame($attempt, $progress->attempts);
        }

        // Attempts exhausted: the fixture leaves the work queue.
        $run = ScrapePlayerStatsJob::dispatchSync(5);
        $this->assertSame(3, PlayerScrapeProgress::first()->attempts);
        $this->assertSame(PipelineRun::STATUS_SUCCESS, PipelineRun::latest('id')->first()->status);
    }
}
