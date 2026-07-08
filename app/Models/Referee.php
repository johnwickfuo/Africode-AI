<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referee extends Model
{
    protected $fillable = [
        'name',
        'matches_officiated',
        'avg_yellows_per_match',
        'avg_reds_per_match',
        'avg_fouls_per_match',
    ];

    protected function casts(): array
    {
        return [
            'avg_yellows_per_match' => 'float',
            'avg_reds_per_match' => 'float',
            'avg_fouls_per_match' => 'float',
        ];
    }

    public function fixtures(): HasMany
    {
        return $this->hasMany(Fixture::class);
    }
}
