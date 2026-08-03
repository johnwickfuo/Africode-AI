<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A target no longer identifies a ticket on its own: alongside the
     * original 3x-10000x set there are now "banker" tickets, which reach a
     * big total using only short legs. Three of them can share a 20x target
     * and differ only by how much a single leg is allowed to pay, so a
     * ticket is identified by (family, max_leg_odds, target_odds).
     */
    public function up(): void
    {
        Schema::table('accumulators', function (Blueprint $table) {
            $table->string('family', 8)->default('classic')->after('generated_at');
            // Null on classic tickets, which have no per-leg ceiling.
            $table->decimal('max_leg_odds', 4, 2)->nullable()->after('family');

            $table->index(['generated_at', 'family']);
        });
    }

    public function down(): void
    {
        Schema::table('accumulators', function (Blueprint $table) {
            $table->dropIndex(['generated_at', 'family']);
            $table->dropColumn(['family', 'max_leg_odds']);
        });
    }
};
