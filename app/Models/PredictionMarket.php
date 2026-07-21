<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionMarket extends Model
{
    public const MARKET_GOALS = 'goals';

    public const MARKET_CORNERS = 'corners';

    public const MARKET_CARDS = 'cards';

    public const MARKET_SHOTS_ON_TARGET = 'shots_on_target';

    public const MARKET_BTTS = 'btts';

    public const MARKET_TEAM_GOALS_HOME = 'team_goals_home';

    public const MARKET_TEAM_GOALS_AWAY = 'team_goals_away';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_WON = 'won';

    public const OUTCOME_LOST = 'lost';

    public const OUTCOME_VOID = 'void';

    protected $fillable = [
        'prediction_id',
        'market',
        'line',
        'direction',
        'probability',
        'confidence_margin',
        'outcome',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'line' => 'float',
            'probability' => 'float',
            'confidence_margin' => 'float',
            'settled_at' => 'datetime',
        ];
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }
}
