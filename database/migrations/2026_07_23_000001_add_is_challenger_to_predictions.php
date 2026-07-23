<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            // Challenger-model predictions (e.g. the ML 1X2 model) are stored
            // and settled like champion rows but never surface as the site's
            // picks — the accuracy tracker referees the two head-to-head.
            $table->boolean('is_challenger')->default(false)->after('model_version')->index();
        });
    }

    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->dropColumn('is_challenger');
        });
    }
};
