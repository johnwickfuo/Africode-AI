<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique(); // PL / PD / SA / BL1 / FL1 (football-data.org codes)
            $table->string('name');
            $table->string('country');
            $table->string('fbref_id')->nullable();
            $table->unsignedInteger('apifootball_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leagues');
    }
};
