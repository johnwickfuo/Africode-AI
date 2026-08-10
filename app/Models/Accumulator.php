<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Tickets you could still back: at least one leg has not kicked off.
     * Once the last match starts a ticket is history, whether or not the
     * nightly settlement has scored it yet.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereHas('legs.fixture', fn (Builder $fixture) => $fixture->where('kickoff_utc', '>', now('UTC')));
    }

    /** The mirror of live(): every leg has kicked off. */
    public function scopeStarted(Builder $query): Builder
    {
        return $query->whereDoesntHave('legs.fixture', fn (Builder $fixture) => $fixture->where('kickoff_utc', '>', now('UTC')));
    }

    /**
     * The days this ticket runs over — "Sat 15 Aug" for one day, "Sat 15 –
     * Sun 16 Aug" across two. Read from the legs, so it can never disagree
     * with them. Requires legs.fixture to be loaded.
     */
    public function windowLabel(): string
    {
        $displayTz = config('africode.display_timezone');

        $kickoffs = $this->legs
            ->map(fn (AccumulatorLeg $leg) => $leg->fixture->kickoff_utc->timezone($displayTz))
            ->sort()->values();

        if ($kickoffs->isEmpty()) {
            return '';
        }

        return $kickoffs->first()->isSameDay($kickoffs->last())
            ? $kickoffs->first()->isoFormat('ddd D MMM')
            : $kickoffs->first()->isoFormat('ddd D').' – '.$kickoffs->last()->isoFormat('ddd D MMM');
    }
}
