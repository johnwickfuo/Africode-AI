<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $fillable = [
        'league_id',
        'name',
        'fbref_name',
        'fdcouk_name',
        'footballdata_id',
        'apifootball_id',
        'short_name',
        'logo_url',
    ];

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function homeFixtures(): HasMany
    {
        return $this->hasMany(Fixture::class, 'home_team_id');
    }

    public function awayFixtures(): HasMany
    {
        return $this->hasMany(Fixture::class, 'away_team_id');
    }

    public function matchStats(): HasMany
    {
        return $this->hasMany(MatchStat::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(TeamProfile::class);
    }
}
