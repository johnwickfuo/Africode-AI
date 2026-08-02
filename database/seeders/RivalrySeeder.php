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
     * uplift, section 7.3 of the spec).
     *
     * Rivals are looked up across the whole country rather than one division:
     * many of the fiercest derbies (Derby–Forest, the two Bristol clubs, the
     * Potteries) span tiers, and clubs move between tiers every season. Pairs
     * naming a club we do not track at all are skipped quietly, so the list
     * can include fixtures that only occur in some seasons.
     */
    public function run(): void
    {
        foreach ($this->rivalriesByLeague() as $leagueCode => $rivalries) {
            $league = League::where('code', $leagueCode)->firstOrFail();

            $teamIdsByName = Team::query()
                ->whereHas('league', fn ($query) => $query->where('country', $league->country))
                ->pluck('id', 'name');

            foreach ($rivalries as [$team1, $team2, $name]) {
                if (! isset($teamIdsByName[$team1], $teamIdsByName[$team2])) {
                    continue;
                }

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
            'ELC' => [
                ['Sheffield United', 'Sheffield Wednesday', 'Steel City Derby'],
                ['Birmingham City', 'West Bromwich Albion', 'Second City Derby'],
                ['Derby County', 'Nottingham Forest', 'East Midlands Derby'],
                ['Ipswich Town', 'Norwich City', 'East Anglian Derby'],
                ['Stoke City', 'Port Vale', 'Potteries Derby'],
                ['Bristol City', 'Bristol Rovers', 'Bristol Derby'],
                ['Swansea City', 'Cardiff City', 'South Wales Derby'],
                ['Millwall', 'Charlton Athletic', 'South London Derby'],
                ['Blackburn Rovers', 'Preston North End', 'East Lancashire Derby'],
                ['Hull City', 'Middlesbrough', 'Humber-Tees Rivalry'],
            ],
            'EL1' => [
                ['Bolton Wanderers', 'Wigan Athletic', 'Greater Manchester Derby'],
                ['Barnsley', 'Huddersfield Town', 'Yorkshire Derby'],
                ['Blackpool', 'Bolton Wanderers', 'Lancashire Derby'],
                ['Plymouth Argyle', 'Exeter City', 'Devon Derby'],
                ['Reading', 'Wycombe Wanderers', 'Berkshire Rivalry'],
                ['Cardiff City', 'Luton Town', 'Play-off Rivalry'],
                ['Port Vale', 'Crewe Alexandra', 'Cheshire Derby'],
            ],
            'EL2' => [
                ['Notts County', 'Chesterfield', 'Notts-Derbyshire Derby'],
                ['Grimsby Town', 'Cheltenham Town', 'League Rivalry'],
                ['Tranmere Rovers', 'Chesterfield', 'League Rivalry'],
                ['Bristol Rovers', 'Swindon Town', 'West Country Derby'],
                ['Colchester United', 'Cambridge United', 'East Anglian Derby'],
                ['Newport County', 'Bromley', 'League Rivalry'],
                ['Crewe Alexandra', 'Shrewsbury Town', 'Shropshire-Cheshire Derby'],
            ],
            'SPL' => [
                ['Celtic', 'Rangers', 'Old Firm'],
                ['Heart of Midlothian', 'Hibernian', 'Edinburgh Derby'],
                ['Dundee', 'Dundee United', 'Dundee Derby'],
                ['Aberdeen', 'Celtic', 'Northern Rivalry'],
                ['Aberdeen', 'Rangers', 'Northern Rivalry'],
                ['Motherwell', 'Kilmarnock', 'Ayrshire-Lanarkshire Rivalry'],
            ],
            'TSL' => [
                ['Galatasaray', 'Fenerbahçe', 'Intercontinental Derby'],
                ['Beşiktaş', 'Galatasaray', 'Istanbul Derby'],
                ['Beşiktaş', 'Fenerbahçe', 'Istanbul Derby'],
                ['Trabzonspor', 'Fenerbahçe', 'Karadeniz Rivalry'],
                ['Trabzonspor', 'Galatasaray', 'Karadeniz Rivalry'],
                ['İstanbul Başakşehir', 'Fatih Karagümrük', 'Istanbul Derby'],
            ],
            'DED' => [
                ['Ajax', 'Feyenoord', 'De Klassieker'],
                ['Ajax', 'PSV Eindhoven', 'Topper'],
                ['PSV Eindhoven', 'Feyenoord', 'Topper'],
                ['Feyenoord', 'Sparta Rotterdam', 'Rotterdam Derby'],
                ['Feyenoord', 'Excelsior', 'Rotterdam Derby'],
                ['FC Twente', 'Heracles Almelo', 'Twente Derby'],
                ['NEC Nijmegen', 'FC Utrecht', 'Rivalry'],
            ],
            'PPL' => [
                ['Benfica', 'FC Porto', 'O Clássico'],
                ['Benfica', 'Sporting CP', 'Derby de Lisboa'],
                ['FC Porto', 'Sporting CP', 'Big Three Clash'],
                ['FC Porto', 'SC Braga', 'Minho-Porto Rivalry'],
                ['SC Braga', 'Vitória Guimarães', 'Minho Derby'],
                ['Sporting CP', 'Estoril Praia', 'Lisbon Area Derby'],
            ],
        ];
    }
}
