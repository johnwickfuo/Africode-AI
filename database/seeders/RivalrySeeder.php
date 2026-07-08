<?php

namespace Database\Seeders;

use App\Models\League;
use App\Models\Rivalry;
use App\Models\Team;
use Illuminate\Database\Seeder;

class RivalrySeeder extends Seeder
{
    /**
     * Static derby/rivalry list used to flag fixtures.is_derby (cards-model
     * uplift, section 7.3 of the spec). Only pairs where BOTH teams are in
     * the 2025-26 top flight are listed — rivalries against relegated or
     * lower-division sides (e.g. Dortmund–Schalke) are omitted until relevant.
     */
    public function run(): void
    {
        foreach ($this->rivalriesByLeague() as $leagueCode => $rivalries) {
            $league = League::where('code', $leagueCode)->firstOrFail();

            $teamIdsByName = Team::where('league_id', $league->id)
                ->pluck('id', 'name');

            foreach ($rivalries as [$team1, $team2, $name]) {
                Rivalry::updateOrCreate(
                    [
                        'team1_id' => $teamIdsByName[$team1],
                        'team2_id' => $teamIdsByName[$team2],
                    ],
                    [
                        'league_id' => $league->id,
                        'name' => $name,
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, list<array{0: string, 1: string, 2: string}>>
     */
    private function rivalriesByLeague(): array
    {
        return [
            'PL' => [
                ['Arsenal', 'Tottenham Hotspur', 'North London Derby'],
                ['Liverpool', 'Everton', 'Merseyside Derby'],
                ['Liverpool', 'Manchester United', 'North-West Derby'],
                ['Manchester City', 'Manchester United', 'Manchester Derby'],
                ['Newcastle United', 'Sunderland', 'Tyne-Wear Derby'],
                ['Leeds United', 'Manchester United', 'Roses Rivalry'],
                ['Crystal Palace', 'Brighton & Hove Albion', 'M23 Derby'],
                ['Chelsea', 'Arsenal', 'London Derby'],
                ['Chelsea', 'Tottenham Hotspur', 'London Derby'],
                ['West Ham United', 'Tottenham Hotspur', 'London Derby'],
                ['Chelsea', 'Fulham', 'West London Derby'],
                ['Fulham', 'Brentford', 'West London Derby'],
            ],
            'PD' => [
                ['Real Madrid', 'FC Barcelona', 'El Clásico'],
                ['Real Madrid', 'Atlético Madrid', 'Madrid Derby'],
                ['Rayo Vallecano', 'Atlético Madrid', 'Madrid Derby'],
                ['Rayo Vallecano', 'Real Madrid', 'Madrid Derby'],
                ['Sevilla', 'Real Betis', 'Seville Derby'],
                ['Athletic Club', 'Real Sociedad', 'Basque Derby'],
                ['FC Barcelona', 'Espanyol', 'Barcelona Derby'],
                ['Valencia', 'Levante', 'Valencia Derby'],
                ['Valencia', 'Villarreal', 'Comunitat Derby'],
            ],
            'SA' => [
                ['Inter Milan', 'AC Milan', 'Derby della Madonnina'],
                ['AS Roma', 'Lazio', 'Derby della Capitale'],
                ['Juventus', 'Torino', 'Derby della Mole'],
                ['Inter Milan', 'Juventus', "Derby d'Italia"],
                ['AS Roma', 'Napoli', 'Derby del Sole'],
                ['Bologna', 'Fiorentina', "Derby dell'Appennino"],
            ],
            'BL1' => [
                ['Bayern Munich', 'Borussia Dortmund', 'Der Klassiker'],
                ['Hamburger SV', 'FC St. Pauli', 'Hamburg Derby'],
                ['Hamburger SV', 'Werder Bremen', 'Nordderby'],
                ['1. FC Köln', 'Borussia Mönchengladbach', 'Rheinderby'],
                ['1. FC Köln', 'Bayer Leverkusen', 'Rhein Derby'],
                ['Eintracht Frankfurt', 'Mainz 05', 'Rhein-Main Derby'],
            ],
            'FL1' => [
                ['Paris Saint-Germain', 'Olympique de Marseille', 'Le Classique'],
                ['Olympique Lyonnais', 'Olympique de Marseille', 'Choc des Olympiques'],
                ['RC Lens', 'Lille OSC', 'Derby du Nord'],
                ['FC Nantes', 'Stade Rennais', "Derby de l'Ouest"],
                ['OGC Nice', 'AS Monaco', "Derby de la Côte d'Azur"],
                ['Paris Saint-Germain', 'Paris FC', 'Paris Derby'],
                ['Stade Brestois', 'Stade Rennais', 'Derby Breton'],
            ],
        ];
    }
}
