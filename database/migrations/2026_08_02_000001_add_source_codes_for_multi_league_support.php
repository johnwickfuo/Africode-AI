<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Until now `leagues.code` doubled as the football-data.org competition
     * code, and each importer carried its own hardcoded division map. That
     * only works while every tracked league happens to exist in both
     * sources. Most leagues do not: the free football-data.org tier covers
     * 13 competitions, while football-data.co.uk publishes full stats for
     * ~19 divisions. Storing each source's code on the league row lets a
     * league opt into whichever feeds actually carry it.
     */
    public function up(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->string('footballdata_code', 10)->nullable()->after('code');
            $table->string('fdcouk_code', 10)->nullable()->after('footballdata_code');
        });

        Schema::table('teams', function (Blueprint $table) {
            // football-data.co.uk's spelling of the club ("Sheffield Weds").
            // An exact join key removes the guesswork that previously created
            // duplicate clubs when two sources disagreed on a name.
            $table->string('fdcouk_name')->nullable()->after('fbref_name')->index();
        });

        $map = [
            'PL' => ['PL', 'E0'],
            'PD' => ['PD', 'SP1'],
            'SA' => ['SA', 'I1'],
            'BL1' => ['BL1', 'D1'],
            'FL1' => ['FL1', 'F1'],
        ];

        foreach ($map as $code => [$apiCode, $csvCode]) {
            DB::table('leagues')->where('code', $code)->update([
                'footballdata_code' => $apiCode,
                'fdcouk_code' => $csvCode,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropColumn(['footballdata_code', 'fdcouk_code']);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['fdcouk_name']);
            $table->dropColumn('fdcouk_name');
        });
    }
};
