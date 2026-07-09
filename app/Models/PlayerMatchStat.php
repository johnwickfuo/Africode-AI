<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerMatchStat extends Model
{
    protected $fillable = [
        'player_id',
        'fixture_id',
        'team_id',
        'minutes',
        'goals',
        'assists',
        'shots',
        'shots_on_target',
        'yellows',
        'reds',
        'xg',
        'xa',
    ];

    protected function casts(): array
    {
        return [
            'xg' => 'float',
            'xa' => 'float',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /** The team the player appeared for in this match (transfer-stable). */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
