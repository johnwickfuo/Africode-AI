<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixtureOdds extends Model
{
    protected $table = 'fixture_odds';

    protected $fillable = [
        'fixture_id',
        'home_odds',
        'draw_odds',
        'away_odds',
        'over25_odds',
        'under25_odds',
        'source',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'home_odds' => 'float',
            'draw_odds' => 'float',
            'away_odds' => 'float',
            'over25_odds' => 'float',
            'under25_odds' => 'float',
            'fetched_at' => 'datetime',
        ];
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }
}
