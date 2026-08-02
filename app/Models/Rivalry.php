<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rivalry extends Model
{
    protected $fillable = [
        'league_id',
        'team1_id',
        'team2_id',
        'name',
    ];

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function team1(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team1_id');
    }

    public function team2(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team2_id');
    }

    /**
     * Whether the given pair of team ids is a listed rivalry (order-insensitive).
     * Used by the fixture sync to set fixtures.is_derby.
     */
    public static function isDerbyPair(int $teamAId, int $teamBId): bool
    {
        // Both orderings must be checked as AND pairs. Passing arrays to
        // orWhere() does NOT do that: Laravel joins the array's conditions
        // with the same boolean it was given, so ['team1_id' => B,
        // 'team2_id' => A] became "team1 = B OR team2 = A" and flagged
        // unrelated fixtures as derbies.
        return static::query()
            ->where(function ($query) use ($teamAId, $teamBId) {
                $query
                    ->where(fn ($pair) => $pair->where('team1_id', $teamAId)->where('team2_id', $teamBId))
                    ->orWhere(fn ($pair) => $pair->where('team1_id', $teamBId)->where('team2_id', $teamAId));
            })
            ->exists();
    }
}
