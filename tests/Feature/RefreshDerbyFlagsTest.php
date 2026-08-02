<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshDerbyFlagsTest extends TestCase
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

    private function fixture(Team $home, Team $away, bool $isDerby): Fixture
    {
        return Fixture::create([
            'league_id' => $home->league_id, 'season' => '2023-2024',
            'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'kickoff_utc' => '2023-09-01 15:00:00', 'status' => Fixture::STATUS_FINISHED,
            'home_goals' => 1, 'away_goals' => 1, 'is_derby' => $isDerby,
        ]);
    }

    public function test_corrects_stale_flags_in_both_directions(): void
    {
        // Seasons outside the import window kept flags from the old buggy
        // pair check: real derbies unflagged, unrelated fixtures flagged.
        $missed = $this->fixture($this->team('Arsenal'), $this->team('Tottenham Hotspur'), false);
        $wrong = $this->fixture($this->team('Chelsea'), $this->team('Everton'), true);
        $correct = $this->fixture($this->team('Liverpool'), $this->team('Everton'), true);

        $this->artisan('africode:refresh-derby-flags --dry-run')->assertSuccessful();
        $this->assertFalse((bool) $missed->fresh()->is_derby, 'dry run changes nothing');

        $this->artisan('africode:refresh-derby-flags')->assertSuccessful();

        $this->assertTrue((bool) $missed->fresh()->is_derby, 'real derby flagged');
        $this->assertFalse((bool) $wrong->fresh()->is_derby, 'non-derby unflagged');
        $this->assertTrue((bool) $correct->fresh()->is_derby, 'correct flag left alone');
    }
}
