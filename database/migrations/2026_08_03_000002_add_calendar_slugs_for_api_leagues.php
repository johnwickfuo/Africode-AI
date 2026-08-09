<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every league now carries a published-calendar slug, not just the four
     * the free API tier omits.
     *
     * football-data.org is queried for a 14-day window, so a league whose
     * season opens later than that returns nothing and the league vanishes
     * from the site — in early August 2026 that was the Premier League,
     * Ligue 1, Serie A and the Bundesliga, all of which had published their
     * fixture lists months earlier. The calendar fills those gaps. The API
     * still owns any match it knows about: kickoff times, matchdays,
     * referees and results.
     */
    public function up(): void
    {
        foreach (self::SLUGS as $code => $slug) {
            DB::table('leagues')->where('code', $code)->update(['calendar_slug' => $slug]);
        }
    }

    public function down(): void
    {
        DB::table('leagues')->whereIn('code', array_keys(self::SLUGS))->update(['calendar_slug' => null]);
    }

    private const SLUGS = [
        'PL' => 'epl',
        'PD' => 'la-liga',
        'SA' => 'serie-a',
        'BL1' => 'bundesliga',
        'FL1' => 'ligue-1',
        'ELC' => 'championship',
        'DED' => 'eredivisie',
        'PPL' => 'primeira-liga',
    ];
};
