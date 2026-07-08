<?php

namespace Database\Seeders;

use App\Models\League;
use Illuminate\Database\Seeder;

class LeagueSeeder extends Seeder
{
    /**
     * The five tracked leagues. Codes are football-data.org competition codes;
     * fbref_id is the FBref competition id (all five are also covered together
     * by soccerdata's "Big 5 European Leagues Combined" reader).
     */
    public function run(): void
    {
        $leagues = [
            ['code' => 'PL', 'name' => 'Premier League', 'country' => 'England', 'fbref_id' => '9'],
            ['code' => 'PD', 'name' => 'La Liga', 'country' => 'Spain', 'fbref_id' => '12'],
            ['code' => 'SA', 'name' => 'Serie A', 'country' => 'Italy', 'fbref_id' => '11'],
            ['code' => 'BL1', 'name' => 'Bundesliga', 'country' => 'Germany', 'fbref_id' => '20'],
            ['code' => 'FL1', 'name' => 'Ligue 1', 'country' => 'France', 'fbref_id' => '13'],
        ];

        foreach ($leagues as $league) {
            League::updateOrCreate(['code' => $league['code']], $league);
        }
    }
}
