<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerScrapeProgress extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const MAX_ATTEMPTS = 3;

    protected $table = 'player_scrape_progress';

    protected $fillable = [
        'fixture_id',
        'status',
        'attempts',
        'last_error',
        'scraped_at',
    ];

    protected function casts(): array
    {
        return [
            'scraped_at' => 'datetime',
        ];
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }
}
