<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'is_derby' => 'boolean',
        ];
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
