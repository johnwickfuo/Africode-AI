<?php

namespace App\Services\Fbref;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Imports the JSON written by scripts/fbref_scrape_players.py.
 *
 * Transfers: players.team_id always reflects the newest match imported for
 * the player (guarded by players.last_seen_at, so an older backfill batch
 * arriving later never regresses it), while every player_match_stats row
 * stores the team the player actually appeared for in that fixture — season
 * history stays accurate whatever order the backfill lands in.
 */
class ImportPlayerStatsService
{
    private const STAT_FIELDS = [
        'minutes', 'goals', 'assists', 'shots', 'shots_on_target',
        'yellows', 'reds', 'xg', 'xa',
    ];

    /**
     * @return array{imported_game_ids: list<string>, failed: array<string, string>, players_created: int, stats_rows: int}
     */
    public function run(?string $path = null): array
    {
        $path ??= config('africode.fbref.player_output_path');

        if (! is_file($path)) {
            throw new RuntimeException("Player stats file not found at {$path}.");
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $result = [
            'imported_game_ids' => [],
            'failed' => [],
            'players_created' => 0,
            'stats_rows' => 0,
        ];

        foreach ($payload['failed_game_ids'] ?? [] as $gameId) {
            $result['failed'][$gameId] = 'scrape failed (see script log)';
        }

        foreach ($payload['matches'] ?? [] as $match) {
            $gameId = $match['game_id'] ?? null;
            if ($gameId === null) {
                continue;
            }

            $fixture = Fixture::with(['homeTeam', 'awayTeam'])
                ->where('fbref_game_id', $gameId)
                ->first();

            if ($fixture === null) {
                $result['failed'][$gameId] = 'no fixture with this fbref_game_id';

                continue;
            }

            $imported = $this->importMatch($fixture, $match['players'] ?? [], $result);

            if ($imported === 0) {
                $result['failed'][$gameId] = 'no player rows could be matched to the fixture teams';
            } else {
                $result['imported_game_ids'][] = $gameId;
            }
        }

        Log::info('Player stats import finished', [
            'imported' => count($result['imported_game_ids']),
            'failed' => count($result['failed']),
            'players_created' => $result['players_created'],
            'stats_rows' => $result['stats_rows'],
        ]);

        return $result;
    }

    private function importMatch(Fixture $fixture, array $players, array &$result): int
    {
        // A match report only contains the two participating squads.
        $teamsByFbrefName = [
            $fixture->homeTeam->fbref_name => $fixture->homeTeam,
            $fixture->awayTeam->fbref_name => $fixture->awayTeam,
        ];

        $imported = 0;

        foreach ($players as $row) {
            $team = $teamsByFbrefName[$row['team'] ?? ''] ?? null;
            if ($team === null || blank($row['player'] ?? null)) {
                continue;
            }

            $player = $this->resolvePlayer($row, $team, $fixture, $result);

            $attributes = ['team_id' => $team->id];
            foreach (self::STAT_FIELDS as $field) {
                $attributes[$field] = $row[$field] ?? null;
            }

            PlayerMatchStat::updateOrCreate(
                ['player_id' => $player->id, 'fixture_id' => $fixture->id],
                $attributes,
            );

            $result['stats_rows']++;
            $imported++;
        }

        return $imported;
    }

    private function resolvePlayer(array $row, Team $team, Fixture $fixture, array &$result): Player
    {
        $player = Player::where('name', $row['player'])
            ->where('nationality', $row['nationality'] ?? null)
            ->first();

        if ($player === null) {
            $result['players_created']++;

            return Player::create([
                'team_id' => $team->id,
                'name' => $row['player'],
                'position' => $row['position'] ?? null,
                'nationality' => $row['nationality'] ?? null,
                'last_seen_at' => $fixture->kickoff_utc,
            ]);
        }

        // Only a newer match than anything seen before may move the player's
        // current team/position — backfill batches arrive out of order.
        if ($player->last_seen_at === null || $fixture->kickoff_utc->gte($player->last_seen_at)) {
            $player->update([
                'team_id' => $team->id,
                'position' => $row['position'] ?? $player->position,
                'last_seen_at' => $fixture->kickoff_utc,
            ]);
        }

        return $player;
    }
}
