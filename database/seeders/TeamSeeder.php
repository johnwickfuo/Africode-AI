<?php

namespace Database\Seeders;

use App\Models\League;
use App\Models\Team;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    /**
     * 2025-26 season squads for the five tracked leagues.
     *
     * fbref_name is the squad name as it appears in FBref/soccerdata match-log
     * tables — the join key for the nightly FBref import. footballdata_id is
     * intentionally NOT seeded: the football-data.org fixture sync (build order
     * step 2) fills it by matching team names, keeping this seeder free of
     * hardcoded third-party ids that could drift.
     */
    public function run(): void
    {
        foreach ($this->teamsByLeague() as $leagueCode => $teams) {
            $league = League::where('code', $leagueCode)->firstOrFail();

            foreach ($teams as [$name, $fbrefName, $shortName]) {
                Team::updateOrCreate(
                    ['league_id' => $league->id, 'name' => $name],
                    ['fbref_name' => $fbrefName, 'short_name' => $shortName],
                );
            }
        }
    }

    /**
     * @return array<string, list<array{0: string, 1: string, 2: string}>>
     */
    private function teamsByLeague(): array
    {
        return [
            'PL' => [
                ['Arsenal', 'Arsenal', 'ARS'],
                ['Aston Villa', 'Aston Villa', 'AVL'],
                ['AFC Bournemouth', 'Bournemouth', 'BOU'],
                ['Brentford', 'Brentford', 'BRE'],
                ['Brighton & Hove Albion', 'Brighton', 'BHA'],
                ['Burnley', 'Burnley', 'BUR'],
                ['Chelsea', 'Chelsea', 'CHE'],
                ['Crystal Palace', 'Crystal Palace', 'CRY'],
                ['Everton', 'Everton', 'EVE'],
                ['Fulham', 'Fulham', 'FUL'],
                ['Leeds United', 'Leeds United', 'LEE'],
                ['Liverpool', 'Liverpool', 'LIV'],
                ['Manchester City', 'Manchester City', 'MCI'],
                ['Manchester United', 'Manchester Utd', 'MUN'],
                ['Newcastle United', 'Newcastle Utd', 'NEW'],
                ['Nottingham Forest', "Nott'ham Forest", 'NFO'],
                ['Sunderland', 'Sunderland', 'SUN'],
                ['Tottenham Hotspur', 'Tottenham', 'TOT'],
                ['West Ham United', 'West Ham', 'WHU'],
                ['Wolverhampton Wanderers', 'Wolves', 'WOL'],
            ],
            'PD' => [
                ['Deportivo Alavés', 'Alavés', 'ALA'],
                ['Athletic Club', 'Athletic Club', 'ATH'],
                ['Atlético Madrid', 'Atlético Madrid', 'ATM'],
                ['FC Barcelona', 'Barcelona', 'BAR'],
                ['Real Betis', 'Betis', 'BET'],
                ['Celta Vigo', 'Celta Vigo', 'CEL'],
                ['Elche', 'Elche', 'ELC'],
                ['Espanyol', 'Espanyol', 'ESP'],
                ['Getafe', 'Getafe', 'GET'],
                ['Girona', 'Girona', 'GIR'],
                ['Levante', 'Levante', 'LEV'],
                ['RCD Mallorca', 'Mallorca', 'MLL'],
                ['Osasuna', 'Osasuna', 'OSA'],
                ['Rayo Vallecano', 'Rayo Vallecano', 'RAY'],
                ['Real Madrid', 'Real Madrid', 'RMA'],
                ['Real Oviedo', 'Oviedo', 'OVI'],
                ['Real Sociedad', 'Real Sociedad', 'RSO'],
                ['Sevilla', 'Sevilla', 'SEV'],
                ['Valencia', 'Valencia', 'VAL'],
                ['Villarreal', 'Villarreal', 'VIL'],
            ],
            'SA' => [
                ['Atalanta', 'Atalanta', 'ATA'],
                ['Bologna', 'Bologna', 'BOL'],
                ['Cagliari', 'Cagliari', 'CAG'],
                ['Como', 'Como', 'COM'],
                ['Cremonese', 'Cremonese', 'CRE'],
                ['Fiorentina', 'Fiorentina', 'FIO'],
                ['Genoa', 'Genoa', 'GEN'],
                ['Hellas Verona', 'Hellas Verona', 'VER'],
                ['Inter Milan', 'Inter', 'INT'],
                ['Juventus', 'Juventus', 'JUV'],
                ['Lazio', 'Lazio', 'LAZ'],
                ['Lecce', 'Lecce', 'LEC'],
                ['AC Milan', 'Milan', 'MIL'],
                ['Napoli', 'Napoli', 'NAP'],
                ['Parma', 'Parma', 'PAR'],
                ['Pisa', 'Pisa', 'PIS'],
                ['AS Roma', 'Roma', 'ROM'],
                ['Sassuolo', 'Sassuolo', 'SAS'],
                ['Torino', 'Torino', 'TOR'],
                ['Udinese', 'Udinese', 'UDI'],
            ],
            'BL1' => [
                ['FC Augsburg', 'Augsburg', 'FCA'],
                ['Bayer Leverkusen', 'Leverkusen', 'B04'],
                ['Bayern Munich', 'Bayern Munich', 'FCB'],
                ['Borussia Dortmund', 'Dortmund', 'BVB'],
                ['Borussia Mönchengladbach', 'Gladbach', 'BMG'],
                ['Eintracht Frankfurt', 'Eint Frankfurt', 'SGE'],
                ['SC Freiburg', 'Freiburg', 'SCF'],
                ['Hamburger SV', 'Hamburger SV', 'HSV'],
                ['1. FC Heidenheim', 'Heidenheim', 'HDH'],
                ['TSG Hoffenheim', 'Hoffenheim', 'TSG'],
                ['1. FC Köln', 'Köln', 'KOE'],
                ['Mainz 05', 'Mainz 05', 'M05'],
                ['RB Leipzig', 'RB Leipzig', 'RBL'],
                ['FC St. Pauli', 'St. Pauli', 'STP'],
                ['VfB Stuttgart', 'Stuttgart', 'VFB'],
                ['Union Berlin', 'Union Berlin', 'FCU'],
                ['Werder Bremen', 'Werder Bremen', 'SVW'],
                ['VfL Wolfsburg', 'Wolfsburg', 'WOB'],
            ],
            'FL1' => [
                ['Angers SCO', 'Angers', 'ANG'],
                ['AJ Auxerre', 'Auxerre', 'AJA'],
                ['Stade Brestois', 'Brest', 'BRE'],
                ['Le Havre', 'Le Havre', 'HAC'],
                ['RC Lens', 'Lens', 'RCL'],
                ['Lille OSC', 'Lille', 'LIL'],
                ['FC Lorient', 'Lorient', 'FCL'],
                ['Olympique Lyonnais', 'Lyon', 'OL'],
                ['Olympique de Marseille', 'Marseille', 'OM'],
                ['FC Metz', 'Metz', 'MET'],
                ['AS Monaco', 'Monaco', 'ASM'],
                ['FC Nantes', 'Nantes', 'NAN'],
                ['OGC Nice', 'Nice', 'OGC'],
                ['Paris FC', 'Paris FC', 'PFC'],
                ['Paris Saint-Germain', 'Paris S-G', 'PSG'],
                ['Stade Rennais', 'Rennes', 'REN'],
                ['RC Strasbourg', 'Strasbourg', 'RCS'],
                ['Toulouse FC', 'Toulouse', 'TFC'],
            ],
        ];
    }
}
