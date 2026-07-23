<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prediction extends Model
{
    protected $fillable = [
        'fixture_id',
        'generated_at',
        'model_version',
        'is_challenger',
        'best_bet_market',
        'best_bet_line',
        'best_bet_direction',
        'best_bet_probability',
        'headline_text',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'is_challenger' => 'boolean',
            'best_bet_line' => 'float',
            'best_bet_probability' => 'float',
        ];
    }

    /**
     * The champion model's predictions — everything user-facing (dashboard,
     * match pages, accas, chatbot) uses this; challenger rows exist only for
     * the accuracy tracker's head-to-head comparison.
     */
    public function scopeChampion(Builder $query): Builder
    {
        return $query->where('is_challenger', false);
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    public function markets(): HasMany
    {
        return $this->hasMany(PredictionMarket::class);
    }
}
