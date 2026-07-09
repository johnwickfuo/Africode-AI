<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // FBref match-report id from the schedule table; the key that lets the
    // player-stats scraper request a specific match report page.
    public function up(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            $table->string('fbref_game_id', 16)->nullable()->unique()->after('footballdata_match_id');
        });
    }

    public function down(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            $table->dropColumn('fbref_game_id');
        });
    }
};
