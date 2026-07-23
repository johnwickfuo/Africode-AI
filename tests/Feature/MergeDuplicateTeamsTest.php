<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MergeDuplicateTeamsTest extends TestCase
{
    use RefreshDatabase;

    private Team $canonical;

    private Team $dupe;

    private Team $arsenal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->canonical = Team::where('name', 'Newcastle United')->firstOrFail();
        $this->arsenal = Team::where('name', 'Arsenal')->firstOrFail();
        $this->dupe = Team::create([
            'league_id' => $this->canonical->league_id,
            'name' => 'Newcastle',
            'fbref_name' => 'Newcastle',
            'short_name' => 'NEW',
        ]);
    }

    private function fixture(int $homeId, int $awayId, string $season = '2024-2025'): Fixture
    {
        return Fixture::create([
            'league_id' => $this->canonical->league_id, 'season' => $season,
            'home_team_id' => $homeId, 'away_team_id' => $awayId,
            'kickoff_utc' => '2024-10-05 14:00:00', 'status' => Fixture::STATUS_FINISHED,
            'home_goals' => 1, 'away_goals' => 1,
        ]);
    }

    public function test_merges_phantom_fixture_into_canonical_and_removes_duplicate_team(): void
    {
        // The real fixture with CSV stats (no possession).
        $real = $this->fixture($this->canonical->id, $this->arsenal->id);
        MatchStat::create([
            'fixture_id' => $real->id, 'team_id' => $this->canonical->id, 'is_home' => true,
            'goals' => 1, 'corners_for' => 5, 'xg' => 1.2, 'source' => 'fdcouk',
        ]);

        // The phantom twin with FBref stats (has possession) and a player row.
        $phantom = $this->fixture($this->dupe->id, $this->arsenal->id);
        MatchStat::create([
            'fixture_id' => $phantom->id, 'team_id' => $this->dupe->id, 'is_home' => true,
            'goals' => 1, 'possession' => 55.0, 'source' => 'fbref',
        ]);
        MatchStat::create([
            'fixture_id' => $phantom->id, 'team_id' => $this->arsenal->id, 'is_home' => false,
            'goals' => 1, 'possession' => 45.0, 'source' => 'fbref',
        ]);
        $player = Player::create(['team_id' => $this->dupe->id, 'name' => 'Alexander Isak',
            'last_seen_at' => now()]);
        PlayerMatchStat::create([
            'player_id' => $player->id, 'fixture_id' => $phantom->id, 'team_id' => $this->dupe->id,
            'minutes' => 90, 'goals' => 1,
        ]);

        // A phantom-only fixture in another season: remapped, not deleted.
        $orphan = $this->fixture($this->arsenal->id, $this->dupe->id, '2023-2024');

        $this->artisan('africode:merge-duplicate-teams')->assertSuccessful();

        $this->assertNull(Team::find($this->dupe->id), 'duplicate team deleted');
        $this->assertNull(Fixture::find($phantom->id), 'phantom fixture deleted');

        $merged = MatchStat::where('fixture_id', $real->id)->where('team_id', $this->canonical->id)->first();
        $this->assertSame(55.0, $merged->possession, 'FBref possession folded into the real row');
        $this->assertSame(1.2, $merged->xg, 'existing xG untouched');
        $this->assertSame(5, $merged->corners_for);

        $arsenalRow = MatchStat::where('fixture_id', $real->id)->where('team_id', $this->arsenal->id)->first();
        $this->assertSame(45.0, $arsenalRow->possession, 'opponent row moved to the real fixture');

        $this->assertSame($real->id, PlayerMatchStat::where('player_id', $player->id)->first()->fixture_id);
        $this->assertSame($this->canonical->id, $player->fresh()->team_id);
        $this->assertSame($this->canonical->id, $orphan->fresh()->away_team_id, 'orphan fixture remapped');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $phantom = $this->fixture($this->dupe->id, $this->arsenal->id);

        $this->artisan('africode:merge-duplicate-teams --dry-run')->assertSuccessful();

        $this->assertNotNull(Team::find($this->dupe->id));
        $this->assertNotNull(Fixture::find($phantom->id));
    }

    public function test_missing_pairs_are_skipped_safely(): void
    {
        $this->dupe->delete(); // nothing left to merge anywhere

        $this->artisan('africode:merge-duplicate-teams')->assertSuccessful();

        $this->assertNotNull(Team::find($this->canonical->id));
    }
}
