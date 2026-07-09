<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccumulatorLeg extends Model
{
    protected $fillable = [
        'accumulator_id',
        'prediction_market_id',
        'fixture_id',
        'market',
        'line',
        'direction',
        'probability',
        'odds',
    ];

    protected function casts(): array
    {
        return [
            'line' => 'float',
            'probability' => 'float',
            'odds' => 'float',
        ];
    }

    public function accumulator(): BelongsTo
    {
        return $this->belongsTo(Accumulator::class);
    }

    public function predictionMarket(): BelongsTo
    {
        return $this->belongsTo(PredictionMarket::class);
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }
}
