<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchStat extends Model
{
    public const SOURCE_FBREF = 'fbref';
    public const SOURCE_APIFOOTBALL = 'apifootball';

    protected $fillable = [
        'fixture_id',
        'team_id',
        'is_home',
        'goals',
        'xg',
        'xga',
        'shots',
        'shots_on_target',
        'shots_on_target_against',
        'corners_for',
        'corners_against',
        'crosses',
        'fouls_committed',
        'fouls_drawn',
        'yellows',
        'reds',
        'possession',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'xg' => 'float',
            'xga' => 'float',
            'possession' => 'float',
        ];
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
