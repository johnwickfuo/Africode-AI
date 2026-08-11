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
     * Tickets worth putting in front of anyone: fewer than
     * `accas.retire_at_started_share` of their legs have kicked off.
     *
     * A ticket is not much use once a chunk of it is already running — the
     * price has moved and nobody can back it whole — so it retires well
     * before its last match rather than sitting on the shelf all weekend.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereRaw('('.self::STARTED_LEGS_SQL.') < accumulators.legs_count * ?', [
            now('UTC'), self::retireShare(),
        ]);
    }

    /** The mirror of live(): retired, whether or not it has been scored. */
    public function scopeStarted(Builder $query): Builder
    {
        return $query->whereRaw('('.self::STARTED_LEGS_SQL.') >= accumulators.legs_count * ?', [
            now('UTC'), self::retireShare(),
        ]);
    }

    /** In-memory counterpart of live(). Requires legs.fixture to be loaded. */
    public function isLive(): bool
    {
        if ($this->legs->isEmpty()) {
            return false;
        }

        $started = $this->legs->filter(fn (AccumulatorLeg $leg) => $leg->fixture->kickoff_utc->isPast())->count();

        return $started < $this->legs->count() * self::retireShare();
    }

    private static function retireShare(): float
    {
        return (float) config('africode.accas.retire_at_started_share', 0.30);
    }

    /** Legs of the accumulator in the outer query whose match has kicked off. */
    private const STARTED_LEGS_SQL = <<<'SQL'
        select count(*) from accumulator_legs
        inner join fixtures on fixtures.id = accumulator_legs.fixture_id
        where accumulator_legs.accumulator_id = accumulators.id
          and fixtures.kickoff_utc <= ?
        SQL;

    /**
     * What makes two tickets the same offer: the family, the per-leg
     * ceiling and the target. A definition has at most one live ticket at
     * a time — republishing it every morning would flood the record with
     * copies, and would change a ticket somebody had already backed.
     */
    public function definitionKey(): string
    {
        return static::keyFor($this->family, $this->max_leg_odds, (int) $this->target_odds);
    }

    public static function keyFor(string $family, ?float $maxLegOdds, int $target): string
    {
        return $family.'|'.($maxLegOdds === null ? '' : number_format($maxLegOdds, 2)).'|'.$target;
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
