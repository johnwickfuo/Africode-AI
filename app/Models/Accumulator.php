<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Accumulator extends Model
{
    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_WON = 'won';

    public const OUTCOME_LOST = 'lost';

    public const OUTCOME_VOID = 'void';

    /** Target-odds tickets with no ceiling on what one leg may pay. */
    public const FAMILY_CLASSIC = 'classic';

    /** Big totals assembled only from short, high-probability legs. */
    public const FAMILY_BANKER = 'banker';

    protected $fillable = [
        'generated_at',
        'family',
        'max_leg_odds',
        'target_odds',
        'combined_odds',
        'combined_probability',
        'legs_count',
        'outcome',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'settled_at' => 'datetime',
            'combined_odds' => 'float',
            'combined_probability' => 'float',
            'max_leg_odds' => 'float',
        ];
    }

    public function legs(): HasMany
    {
        return $this->hasMany(AccumulatorLeg::class);
    }
}
