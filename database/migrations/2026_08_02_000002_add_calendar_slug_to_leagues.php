<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leagues outside the football-data.org free tier had no full-season
     * fixture list: football-data.co.uk's fixtures.csv is a ~3-day rolling
     * window, so a division whose season starts next week showed nothing at
     * all, and one already running showed only the next couple of days.
     *
     * fixturedownload.com publishes the complete season calendar for those
     * divisions as a free, keyless CSV. This column holds the slug in its
     * download URL, so opting a league in stays a data change.
     */
    public function up(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->string('calendar_slug', 60)->nullable()->after('fdcouk_code');
        });

        $slugs = [
            'EL1' => 'efl-league-one',
            'EL2' => 'efl-league-two',
            'SPL' => 'scottish-premiership',
            'TSL' => 'super-lig',
        ];

        foreach ($slugs as $code => $slug) {
            DB::table('leagues')->where('code', $code)->update(['calendar_slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropColumn('calendar_slug');
        });
    }
};
