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

            foreach ($teams as $entry) {
                [$name, $fbrefName, $shortName] = $entry;
                $fdcoukName = $entry[3] ?? null;

                // Clubs move between divisions of the same country, so a
                // promoted or relegated side must keep its existing row (and
                // its history) instead of being duplicated into the new
                // league. league_id records where the club currently plays;
                // every match's competition lives on the fixture itself.
                $existing = Team::where('name', $name)
                    ->whereHas('league', fn ($query) => $query->where('country', $league->country))
                    ->first();

                if ($existing !== null) {
                    $existing->update(array_filter([
                        'fbref_name' => $existing->fbref_name ?: $fbrefName,
                        'fdcouk_name' => $fdcoukName,
                        'short_name' => $existing->short_name ?: $shortName,
                    ], fn ($value) => $value !== null));

                    continue;
                }

                Team::updateOrCreate(
                    ['league_id' => $league->id, 'name' => $name],
                    ['fbref_name' => $fbrefName, 'fdcouk_name' => $fdcoukName, 'short_name' => $shortName],
                );
            }
        }
    }

    /**
     * Entries are [display name, FBref name, short name] with an optional
     * fourth element: football-data.co.uk's spelling, which the CSV importer
     * uses as an exact join key.
     *
     * @return array<string, list<array{0: string, 1: string, 2: string, 3?: string}>>
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

            // --- leagues added 2026-27: football-data.co.uk is the only stats
            // source, so every entry carries its exact CSV spelling. ---

            'ELC' => [
                ['Birmingham City', 'Birmingham City', 'BIR', 'Birmingham'],
                ['Blackburn Rovers', 'Blackburn', 'BLB', 'Blackburn'],
                ['Bristol City', 'Bristol City', 'BRC', 'Bristol City'],
                ['Charlton Athletic', 'Charlton Ath', 'CHA', 'Charlton'],
                ['Coventry City', 'Coventry City', 'COV', 'Coventry'],
                ['Derby County', 'Derby County', 'DER', 'Derby'],
                ['Hull City', 'Hull City', 'HUL', 'Hull'],
                ['Ipswich Town', 'Ipswich Town', 'IPS', 'Ipswich'],
                ['Leicester City', 'Leicester City', 'LEI', 'Leicester'],
                ['Middlesbrough', 'Middlesbrough', 'MID', 'Middlesbrough'],
                ['Millwall', 'Millwall', 'MLW', 'Millwall'],
                ['Norwich City', 'Norwich City', 'NOR', 'Norwich'],
                ['Oxford United', 'Oxford United', 'OXF', 'Oxford'],
                ['Portsmouth', 'Portsmouth', 'POR', 'Portsmouth'],
                ['Preston North End', 'Preston', 'PNE', 'Preston'],
                ['Queens Park Rangers', 'QPR', 'QPR', 'QPR'],
                ['Sheffield United', 'Sheffield Utd', 'SHU', 'Sheffield United'],
                ['Sheffield Wednesday', 'Sheffield Weds', 'SHW', 'Sheffield Weds'],
                ['Southampton', 'Southampton', 'SOU', 'Southampton'],
                ['Stoke City', 'Stoke City', 'STK', 'Stoke'],
                ['Swansea City', 'Swansea City', 'SWA', 'Swansea'],
                ['Watford', 'Watford', 'WAT', 'Watford'],
                ['West Bromwich Albion', 'West Brom', 'WBA', 'West Brom'],
                ['Wrexham', 'Wrexham', 'WRX', 'Wrexham'],
            ],

            'EL1' => [
                ['AFC Wimbledon', 'Wimbledon', 'WIM', 'AFC Wimbledon'],
                ['Barnsley', 'Barnsley', 'BAR', 'Barnsley'],
                ['Blackpool', 'Blackpool', 'BLP', 'Blackpool'],
                ['Bolton Wanderers', 'Bolton', 'BOL', 'Bolton'],
                ['Bradford City', 'Bradford City', 'BRD', 'Bradford'],
                ['Burton Albion', 'Burton Albion', 'BUR', 'Burton'],
                ['Cardiff City', 'Cardiff City', 'CAR', 'Cardiff'],
                ['Doncaster Rovers', 'Doncaster', 'DON', 'Doncaster'],
                ['Exeter City', 'Exeter City', 'EXE', 'Exeter'],
                ['Huddersfield Town', 'Huddersfield', 'HUD', 'Huddersfield'],
                ['Leyton Orient', 'Leyton Orient', 'LEY', 'Leyton Orient'],
                ['Lincoln City', 'Lincoln City', 'LIN', 'Lincoln'],
                ['Luton Town', 'Luton Town', 'LUT', 'Luton'],
                ['Mansfield Town', 'Mansfield Town', 'MAN', 'Mansfield'],
                ['Northampton Town', 'Northampton', 'NOR', 'Northampton'],
                ['Peterborough United', 'Peterborough', 'PET', 'Peterboro'],
                ['Plymouth Argyle', 'Plymouth Argyle', 'PLY', 'Plymouth'],
                ['Port Vale', 'Port Vale', 'PVA', 'Port Vale'],
                ['Reading', 'Reading', 'REA', 'Reading'],
                ['Rotherham United', 'Rotherham Utd', 'ROT', 'Rotherham'],
                ['Stevenage', 'Stevenage', 'STE', 'Stevenage'],
                ['Stockport County', 'Stockport', 'STO', 'Stockport'],
                ['Wigan Athletic', 'Wigan Athletic', 'WIG', 'Wigan'],
                ['Wycombe Wanderers', 'Wycombe', 'WYC', 'Wycombe'],
            ],

            'EL2' => [
                ['Accrington Stanley', 'Accrington', 'ACC', 'Accrington'],
                ['Barnet', 'Barnet', 'BNT', 'Barnet'],
                ['Barrow', 'Barrow', 'BRW', 'Barrow'],
                ['Bristol Rovers', 'Bristol Rovers', 'BRR', 'Bristol Rvs'],
                ['Bromley', 'Bromley', 'BRO', 'Bromley'],
                ['Cambridge United', 'Cambridge Utd', 'CAM', 'Cambridge'],
                ['Cheltenham Town', 'Cheltenham', 'CHE', 'Cheltenham'],
                ['Chesterfield', 'Chesterfield', 'CHF', 'Chesterfield'],
                ['Colchester United', 'Colchester', 'COL', 'Colchester'],
                ['Crawley Town', 'Crawley Town', 'CRW', 'Crawley Town'],
                ['Crewe Alexandra', 'Crewe', 'CRE', 'Crewe'],
                ['Fleetwood Town', 'Fleetwood', 'FLE', 'Fleetwood Town'],
                ['Gillingham', 'Gillingham', 'GIL', 'Gillingham'],
                ['Grimsby Town', 'Grimsby', 'GRI', 'Grimsby'],
                ['Harrogate Town', 'Harrogate', 'HAR', 'Harrogate'],
                ['MK Dons', 'Milton Keynes Dons', 'MKD', 'Milton Keynes Dons'],
                ['Newport County', 'Newport County', 'NEW', 'Newport County'],
                ['Notts County', 'Notts County', 'NOT', 'Notts County'],
                ['Oldham Athletic', 'Oldham', 'OLD', 'Oldham'],
                ['Salford City', 'Salford', 'SAL', 'Salford'],
                ['Shrewsbury Town', 'Shrewsbury', 'SHR', 'Shrewsbury'],
                ['Swindon Town', 'Swindon', 'SWI', 'Swindon'],
                ['Tranmere Rovers', 'Tranmere', 'TRA', 'Tranmere'],
                ['Walsall', 'Walsall', 'WAL', 'Walsall'],
            ],

            'SPL' => [
                ['Aberdeen', 'Aberdeen', 'ABE', 'Aberdeen'],
                ['Celtic', 'Celtic', 'CEL', 'Celtic'],
                ['Dundee', 'Dundee', 'DUN', 'Dundee'],
                ['Dundee United', 'Dundee United', 'DUU', 'Dundee United'],
                ['Falkirk', 'Falkirk', 'FAL', 'Falkirk'],
                ['Heart of Midlothian', 'Hearts', 'HEA', 'Hearts'],
                ['Hibernian', 'Hibernian', 'HIB', 'Hibernian'],
                ['Kilmarnock', 'Kilmarnock', 'KIL', 'Kilmarnock'],
                ['Livingston', 'Livingston', 'LIV', 'Livingston'],
                ['Motherwell', 'Motherwell', 'MOT', 'Motherwell'],
                ['Rangers', 'Rangers', 'RAN', 'Rangers'],
                ['St Mirren', 'St Mirren', 'STM', 'St Mirren'],
            ],

            'TSL' => [
                ['Alanyaspor', 'Alanyaspor', 'ALA', 'Alanyaspor'],
                ['Antalyaspor', 'Antalyaspor', 'ANT', 'Antalyaspor'],
                ['Beşiktaş', 'Besiktas', 'BJK', 'Besiktas'],
                ['İstanbul Başakşehir', 'Basaksehir', 'IBF', 'Buyuksehyr'],
                ['Eyüpspor', 'Eyupspor', 'EYU', 'Eyupspor'],
                ['Fenerbahçe', 'Fenerbahce', 'FEN', 'Fenerbahce'],
                ['Galatasaray', 'Galatasaray', 'GAL', 'Galatasaray'],
                ['Gaziantep FK', 'Gaziantep', 'GAZ', 'Gaziantep'],
                ['Gençlerbirliği', 'Genclerbirligi', 'GEN', 'Genclerbirligi'],
                ['Göztepe', 'Goztepe', 'GOZ', 'Goztep'],
                ['Fatih Karagümrük', 'Karagumruk', 'KAR', 'Karagumruk'],
                ['Kasımpaşa', 'Kasimpasa', 'KAS', 'Kasimpasa'],
                ['Kayserispor', 'Kayserispor', 'KAY', 'Kayserispor'],
                ['Kocaelispor', 'Kocaelispor', 'KOC', 'Kocaelispor'],
                ['Konyaspor', 'Konyaspor', 'KON', 'Konyaspor'],
                ['Çaykur Rizespor', 'Rizespor', 'RIZ', 'Rizespor'],
                ['Samsunspor', 'Samsunspor', 'SAM', 'Samsunspor'],
                ['Trabzonspor', 'Trabzonspor', 'TRA', 'Trabzonspor'],
            ],

            'DED' => [
                ['Ajax', 'Ajax', 'AJA', 'Ajax'],
                ['AZ Alkmaar', 'AZ Alkmaar', 'AZ', 'AZ Alkmaar'],
                ['Excelsior', 'Excelsior', 'EXC', 'Excelsior'],
                ['Feyenoord', 'Feyenoord', 'FEY', 'Feyenoord'],
                ['Fortuna Sittard', 'Fortuna Sittard', 'FOR', 'For Sittard'],
                ['Go Ahead Eagles', 'Go Ahead Eag', 'GAE', 'Go Ahead Eagles'],
                ['FC Groningen', 'Groningen', 'GRO', 'Groningen'],
                ['SC Heerenveen', 'Heerenveen', 'HEE', 'Heerenveen'],
                ['Heracles Almelo', 'Heracles', 'HER', 'Heracles'],
                ['NAC Breda', 'NAC Breda', 'NAC', 'NAC Breda'],
                ['NEC Nijmegen', 'Nijmegen', 'NEC', 'Nijmegen'],
                ['PSV Eindhoven', 'PSV Eindhoven', 'PSV', 'PSV Eindhoven'],
                ['Sparta Rotterdam', 'Sparta R.', 'SPA', 'Sparta Rotterdam'],
                ['Telstar', 'Telstar', 'TEL', 'Telstar'],
                ['FC Twente', 'Twente', 'TWE', 'Twente'],
                ['FC Utrecht', 'Utrecht', 'UTR', 'Utrecht'],
                ['FC Volendam', 'Volendam', 'VOL', 'Volendam'],
                ['PEC Zwolle', 'Zwolle', 'ZWO', 'Zwolle'],
            ],

            'PPL' => [
                ['AVS', 'AVS', 'AVS', 'AVS'],
                ['FC Alverca', 'Alverca', 'ALV', 'Alverca'],
                ['FC Arouca', 'Arouca', 'ARO', 'Arouca'],
                ['Benfica', 'Benfica', 'BEN', 'Benfica'],
                ['Casa Pia', 'Casa Pia', 'CAS', 'Casa Pia'],
                ['Estoril Praia', 'Estoril', 'EST', 'Estoril'],
                ['Estrela da Amadora', 'Estrela', 'ESA', 'Estrela'],
                ['Famalicão', 'Famalicao', 'FAM', 'Famalicao'],
                ['Gil Vicente', 'Gil Vicente', 'GIL', 'Gil Vicente'],
                ['Vitória Guimarães', 'Guimaraes', 'VIT', 'Guimaraes'],
                ['Moreirense', 'Moreirense', 'MOR', 'Moreirense'],
                ['Nacional', 'Nacional', 'NAC', 'Nacional'],
                ['FC Porto', 'Porto', 'POR', 'Porto'],
                ['Rio Ave', 'Rio Ave', 'RIO', 'Rio Ave'],
                ['Santa Clara', 'Santa Clara', 'SCL', 'Santa Clara'],
                ['SC Braga', 'Braga', 'BRA', 'Sp Braga'],
                ['Sporting CP', 'Sporting CP', 'SCP', 'Sp Lisbon'],
                ['Tondela', 'Tondela', 'TON', 'Tondela'],
            ],
        ];
    }
}
