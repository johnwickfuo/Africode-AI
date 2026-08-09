<?php

namespace Database\Seeders;

use App\Models\League;
use Illuminate\Database\Seeder;

class LeagueSeeder extends Seeder
{
    /**
     * Tracked competitions.
     *
     *  - code: our stable internal identifier (used in URLs, chat tools).
     *  - footballdata_code: football-data.org competition code, or null when
     *    the free tier does not carry the league — those leagues take their
     *    upcoming fixtures from football-data.co.uk's fixtures.csv instead.
     *  - fdcouk_code: football-data.co.uk division file, the source of match
     *    stats and bookmaker odds for every league here.
     *  - calendar_slug: fixturedownload.com slug. Every league has one: the
     *    API is only queried for a 14-day window, so a league whose season
     *    opens later than that would otherwise show nothing at all, and the
     *    four leagues off the free tier have no API fixtures ever.
     *  - fbref_id: FBref competition id (big five only; the FBref scrape is
     *    configured for the "Big 5 European Leagues Combined" reader).
     */
    public function run(): void
    {
        $leagues = [
            // Big five — API fixtures, CSV stats, Understat xG.
            ['code' => 'PL', 'footballdata_code' => 'PL', 'fdcouk_code' => 'E0', 'calendar_slug' => 'epl', 'name' => 'Premier League', 'country' => 'England', 'fbref_id' => '9'],
            ['code' => 'PD', 'footballdata_code' => 'PD', 'fdcouk_code' => 'SP1', 'calendar_slug' => 'la-liga', 'name' => 'La Liga', 'country' => 'Spain', 'fbref_id' => '12'],
            ['code' => 'SA', 'footballdata_code' => 'SA', 'fdcouk_code' => 'I1', 'calendar_slug' => 'serie-a', 'name' => 'Serie A', 'country' => 'Italy', 'fbref_id' => '11'],
            ['code' => 'BL1', 'footballdata_code' => 'BL1', 'fdcouk_code' => 'D1', 'calendar_slug' => 'bundesliga', 'name' => 'Bundesliga', 'country' => 'Germany', 'fbref_id' => '20'],
            ['code' => 'FL1', 'footballdata_code' => 'FL1', 'fdcouk_code' => 'F1', 'calendar_slug' => 'ligue-1', 'name' => 'Ligue 1', 'country' => 'France', 'fbref_id' => '13'],

            // On the football-data.org free tier: API fixtures + CSV stats.
            ['code' => 'ELC', 'footballdata_code' => 'ELC', 'fdcouk_code' => 'E1', 'calendar_slug' => 'championship', 'name' => 'Championship', 'country' => 'England', 'fbref_id' => '10'],
            ['code' => 'DED', 'footballdata_code' => 'DED', 'fdcouk_code' => 'N1', 'calendar_slug' => 'eredivisie', 'name' => 'Eredivisie', 'country' => 'Netherlands', 'fbref_id' => '23'],
            ['code' => 'PPL', 'footballdata_code' => 'PPL', 'fdcouk_code' => 'P1', 'calendar_slug' => 'primeira-liga', 'name' => 'Primeira Liga', 'country' => 'Portugal', 'fbref_id' => '32'],

            // Not on the free API tier. Their season calendar comes from
            // fixturedownload.com, so they carry the same 14-day upcoming
            // horizon as everything else; fixtures.csv still prices them.
            ['code' => 'EL1', 'footballdata_code' => null, 'fdcouk_code' => 'E2', 'calendar_slug' => 'efl-league-one', 'name' => 'League One', 'country' => 'England', 'fbref_id' => '15'],
            ['code' => 'EL2', 'footballdata_code' => null, 'fdcouk_code' => 'E3', 'calendar_slug' => 'efl-league-two', 'name' => 'League Two', 'country' => 'England', 'fbref_id' => '16'],
            ['code' => 'SPL', 'footballdata_code' => null, 'fdcouk_code' => 'SC0', 'calendar_slug' => 'scottish-premiership', 'name' => 'Scottish Premiership', 'country' => 'Scotland', 'fbref_id' => '40'],
            ['code' => 'TSL', 'footballdata_code' => null, 'fdcouk_code' => 'T1', 'calendar_slug' => 'super-lig', 'name' => 'Süper Lig', 'country' => 'Turkey', 'fbref_id' => '26'],
        ];

        foreach ($leagues as $league) {
            League::updateOrCreate(['code' => $league['code']], $league);
        }
    }
}
