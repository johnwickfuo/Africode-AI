<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\Team;
use App\Services\Chat\ChatToolbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatPlayerToolsTest extends TestCase
{
    use RefreshDatabase;

    private ChatToolbox $toolbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->toolbox = app(ChatToolbox::class);
    }

    private function team(string $name): Team
    {
        return Team::where('name', $name)->firstOrFail();
    }

    private function playerWithMatches(string $name, Team $team, array $matches, string $nationality = 'ENG'): Player
    {
        $player = Player::create([
            'team_id' => $team->id, 'name' => $name, 'position' => 'FW',
            'nationality' => $nationality, 'last_seen_at' => now(),
        ]);

        foreach ($matches as $index => $stats) {
            $opponent = Team::where('league_id', $team->league_id)->whereKeyNot($team->id)
                ->orderBy('id')->skip($index)->first();
            $fixture = Fixture::create([
                'league_id' => $team->league_id, 'season' => $stats['season'] ?? '2025-2026',
                'home_team_id' => $team->id, 'away_team_id' => $opponent->id,
                'kickoff_utc' => now('UTC')->subDays(30 - $index * 7),
                'status' => Fixture::STATUS_FINISHED, 'home_goals' => 2, 'away_goals' => 1,
            ]);
            PlayerMatchStat::create([
                'player_id' => $player->id, 'fixture_id' => $fixture->id, 'team_id' => $team->id,
                'minutes' => 90,
            ] + collect($stats)->except('season')->all());
        }

        return $player;
    }

    public function test_get_player_stats_fuzzy_matches_and_aggregates(): void
    {
        $this->playerWithMatches('Bukayo Saka', $this->team('Arsenal'), [
            ['goals' => 2, 'assists' => 1, 'shots' => 5, 'shots_on_target' => 3, 'xg' => 1.1, 'xa' => 0.5],
            ['goals' => 1, 'assists' => 0, 'shots' => 3, 'shots_on_target' => 1, 'yellows' => 1, 'xg' => 0.7, 'xa' => 0.2],
        ]);

        $result = $this->toolbox->execute('get_player_stats', ['player' => 'Saka']);

        $this->assertSame('Bukayo Saka', $result['player']);
        $this->assertSame('Arsenal', $result['team']);
        $this->assertSame('2025-2026', $result['season']);
        $this->assertSame(3, $result['totals']['goals']);
        $this->assertSame(2, $result['totals']['matches']);
        $this->assertEqualsWithDelta(1.8, $result['totals']['xg'], 0.001);
        $this->assertEqualsWithDelta(1.5, $result['per_match']['goals'], 0.001);
        $this->assertStringContainsString('backfilled', $result['note']);
    }

    public function test_get_player_stats_prefers_shortest_name_match(): void
    {
        $this->playerWithMatches('Danilo Pereira', $this->team('Chelsea'), [['goals' => 0]], 'POR');
        $this->playerWithMatches('Danilo', $this->team('Arsenal'), [['goals' => 1]], 'BRA');

        $result = $this->toolbox->execute('get_player_stats', ['player' => 'Danilo']);

        $this->assertSame('Danilo', $result['player']);
    }

    public function test_get_top_players_leaderboard_with_league_filter(): void
    {
        $this->playerWithMatches('Bukayo Saka', $this->team('Arsenal'), [
            ['goals' => 2], ['goals' => 1],
        ]);
        $this->playerWithMatches('Erling Haaland', $this->team('Manchester City'), [
            ['goals' => 3], ['goals' => 2],
        ], 'NOR');
        // A La Liga scorer that a PL filter must exclude.
        $this->playerWithMatches('Kylian Mbappé', $this->team('Real Madrid'), [
            ['goals' => 4], ['goals' => 4],
        ], 'FRA');

        $result = $this->toolbox->execute('get_top_players', ['stat' => 'goals', 'league' => 'PL']);

        $this->assertSame('Erling Haaland', $result['leaders'][0]['player']);
        $this->assertSame(5, $result['leaders'][0]['total']);
        $this->assertSame('Bukayo Saka', $result['leaders'][1]['player']);
        $this->assertCount(2, $result['leaders']);

        $all = $this->toolbox->execute('get_top_players', ['stat' => 'goals']);
        $this->assertSame('Kylian Mbappé', $all['leaders'][0]['player']);

        // Cards leaderboard counts yellows + reds.
        $this->playerWithMatches('Cristian Romero', $this->team('Tottenham Hotspur'), [
            ['yellows' => 2, 'reds' => 1],
        ], 'ARG');
        $cards = $this->toolbox->execute('get_top_players', ['stat' => 'cards', 'league' => 'PL']);
        $this->assertSame('Cristian Romero', $cards['leaders'][0]['player']);
        $this->assertSame(3, $cards['leaders'][0]['total']);
    }

    public function test_get_player_recent_form_returns_last_five(): void
    {
        $this->playerWithMatches('Bukayo Saka', $this->team('Arsenal'), array_fill(0, 7, ['goals' => 1, 'shots' => 3]));

        $result = $this->toolbox->execute('get_player_recent_form', ['player' => 'saka']);

        $this->assertCount(5, $result['last_matches']);
        $this->assertSame(1, $result['last_matches'][0]['goals']);
        // Newest first.
        $this->assertGreaterThan(
            $result['last_matches'][4]['date'],
            $result['last_matches'][0]['date'],
        );
    }

    public function test_player_tools_validate_and_degrade_gracefully(): void
    {
        // Unknown player mentions the running backfill.
        $missing = $this->toolbox->execute('get_player_stats', ['player' => 'Zlatan']);
        $this->assertStringContainsString('backfilled', $missing['error']);

        // Whitelisted stats only.
        $this->assertArrayHasKey('error', $this->toolbox->execute('get_top_players', ['stat' => 'nutmegs']));
        // Bad season format rejected.
        $this->assertArrayHasKey('error', $this->toolbox->execute('get_player_stats', ['player' => 'Saka', 'season' => '25/26']));
        // Empty database degrades to a friendly result, not an exception.
        $empty = $this->toolbox->execute('get_top_players', ['stat' => 'goals']);
        $this->assertArrayHasKey('result', $empty);
    }
}
