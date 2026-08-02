<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Fixture extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_POSTPONED = 'postponed';

    protected $fillable = [
        'league_id',
        'season',
        'matchday',
        'home_team_id',
        'away_team_id',
        'kickoff_utc',
        'kickoff_confirmed',
        'status',
        'referee_id',
        'footballdata_match_id',
        'fbref_game_id',
        'home_goals',
        'away_goals',
        'is_derby',
    ];

    protected function casts(): array
    {
        return [
            'kickoff_utc' => 'datetime',
            'kickoff_confirmed' => 'boolean',
            'is_derby' => 'boolean',
        ];
    }

    /**
     * Kickoff in the display timezone. Where the only published calendar
     * carries a placeholder time, the date still stands but the clock does
     * not, so it reads "TBC" rather than something confidently wrong.
     */
    public function kickoffLabel(string $dateFormat = 'ddd D MMM'): string
    {
        $local = $this->kickoff_utc->timezone(config('africode.display_timezone'));

        return $local->isoFormat($dateFormat).', '.($this->kickoff_confirmed ? $local->format('H:i') : 'TBC');
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(Referee::class);
    }

    public function matchStats(): HasMany
    {
        return $this->hasMany(MatchStat::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function odds(): HasOne
    {
        return $this->hasOne(FixtureOdds::class);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_SCHEDULED)
            ->where('kickoff_utc', '>=', now('UTC'))
            ->orderBy('kickoff_utc');
    }

    public function scopeFinished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FINISHED);
    }
}
