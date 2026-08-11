<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `odds` used to be the model's fair price (1/p). It now holds what a
     * bookmaker would actually pay, which is the number worth advertising —
     * a ticket built to fair odds returns roughly two thirds of its stated
     * total once margin is taken, and worse the more legs it carries.
     *
     * The fair price is kept alongside, because it is what the model
     * actually claimed and the accuracy record is judged against it.
     */
    public function up(): void
    {
        Schema::table('accumulator_legs', function (Blueprint $table) {
            $table->decimal('model_odds', 10, 3)->nullable()->after('odds');
        });

        Schema::table('accumulators', function (Blueprint $table) {
            $table->decimal('model_combined_odds', 14, 2)->nullable()->after('combined_odds');
        });

        // Existing rows were built at fair odds, so that is what both
        // columns hold until those tickets retire and are rebuilt.
        DB::statement('update accumulator_legs set model_odds = odds');
        DB::statement('update accumulators set model_combined_odds = combined_odds');
    }

    public function down(): void
    {
        Schema::table('accumulator_legs', function (Blueprint $table) {
            $table->dropColumn('model_odds');
        });

        Schema::table('accumulators', function (Blueprint $table) {
            $table->dropColumn('model_combined_odds');
        });
    }
};
