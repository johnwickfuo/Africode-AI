<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some published season calendars carry the right dates but a single
     * placeholder kick-off time on every match (the Süper Lig list is all
     * 21:00), because the broadcaster picks have not been made yet.
     * Rendering that as a real time is worse than admitting it is unknown,
     * so a fixture records whether its time can be trusted. Every other
     * source sets it true.
     */
    public function up(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            $table->boolean('kickoff_confirmed')->default(true)->after('kickoff_utc');
        });
    }

    public function down(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            $table->dropColumn('kickoff_confirmed');
        });
    }
};
