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
        return static::query()
            ->where(function ($query) use ($teamAId, $teamBId) {
                $query->where(['team1_id' => $teamAId, 'team2_id' => $teamBId])
                    ->orWhere(['team1_id' => $teamBId, 'team2_id' => $teamAId]);
            })
            ->exists();
    }
}
