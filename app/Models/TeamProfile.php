<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamProfile extends Model
{
    protected $fillable = [
        'team_id',
        'league_id',
        'season',
        'matches_played',
        'attack_strength',
        'defence_strength',
        'xg_for_avg',
        'xg_against_avg',
        'corners_for_avg',
        'corners_against_avg',
        'crosses_avg',
        'cards_avg',
        'fouls_committed_avg',
        'fouls_drawn_avg',
        'sot_for_avg',
        'sot_against_avg',
        'home_advantage_factor',
    ];

    protected function casts(): array
    {
        return [
            'attack_strength' => 'float',
            'defence_strength' => 'float',
            'xg_for_avg' => 'float',
            'xg_against_avg' => 'float',
            'corners_for_avg' => 'float',
            'corners_against_avg' => 'float',
            'crosses_avg' => 'float',
            'cards_avg' => 'float',
            'fouls_committed_avg' => 'float',
            'fouls_drawn_avg' => 'float',
            'sot_for_avg' => 'float',
            'sot_against_avg' => 'float',
            'home_advantage_factor' => 'float',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The division the row was measured in. Attack and defence strengths are
     * relative to this league's average, so they only compare like for like.
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }
}
