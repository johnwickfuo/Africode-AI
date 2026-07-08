<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class League extends Model
{
    public const CODE_PREMIER_LEAGUE = 'PL';
    public const CODE_LA_LIGA = 'PD';
    public const CODE_SERIE_A = 'SA';
    public const CODE_BUNDESLIGA = 'BL1';
    public const CODE_LIGUE_1 = 'FL1';

    protected $fillable = [
        'code',
        'name',
        'country',
        'fbref_id',
        'apifootball_id',
    ];

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function fixtures(): HasMany
    {
        return $this->hasMany(Fixture::class);
    }

    public function rivalries(): HasMany
    {
        return $this->hasMany(Rivalry::class);
    }
}
