<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    protected $fillable = [
        'team_id',
        'name',
        'fbref_id',
        'position',
        'nationality',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /** The player's current team (moves on transfer). */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function matchStats(): HasMany
    {
        return $this->hasMany(PlayerMatchStat::class);
    }
}
