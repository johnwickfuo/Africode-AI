<?php

namespace App\Http\Controllers;

use App\Models\Fixture;
use App\Models\League;
use App\Models\Prediction;
use App\Services\FootballData\FixtureSyncService;
use App\Services\Odds\ValueBets;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private ValueBets $valueBets) {}

    /**
     * Upcoming fixtures for the next 14 days with each fixture's latest
     * Best Bet. Reads only from the database — no computation here.
     */
    public function __invoke(): Response
    {
        $displayTz = config('africode.display_timezone');

        $fixtures = Fixture::upcoming()
            ->where('kickoff_utc', '<=', now('UTC')->addDays(FixtureSyncService::DAYS_AHEAD))
            ->with([
                'league:id,code,name',
                'homeTeam:id,name,short_name,logo_url',
                'awayTeam:id,name,short_name,logo_url',
                'odds',
                'predictions' => fn ($query) => $query->champion()->orderByDesc('generated_at'),
                'predictions.markets',
            ])
            ->get()
            ->map(function (Fixture $fixture) use ($displayTz) {
                /** @var Prediction|null $prediction */
                $prediction = $fixture->predictions->first();
                $value = $this->valueBets->bestEdge($prediction, $fixture->odds);

                return [
                    'id' => $fixture->id,
                    'league' => [
                        'code' => $fixture->league->code,
                        'name' => $fixture->league->name,
                    ],
                    'home_team' => $fixture->homeTeam->only(['name', 'short_name', 'logo_url']),
                    'away_team' => $fixture->awayTeam->only(['name', 'short_name', 'logo_url']),
                    'kickoff_date' => $fixture->kickoff_utc->timezone($displayTz)->isoFormat('dddd D MMMM'),
                    'kickoff_time' => $fixture->kickoff_utc->timezone($displayTz)->format('H:i'),
                    'matchday' => $fixture->matchday,
                    'is_derby' => $fixture->is_derby,
                    'best_bet' => $prediction === null ? null : [
                        'headline' => $prediction->headline_text,
                        'probability' => (float) $prediction->best_bet_probability,
                        'market' => $prediction->best_bet_market,
                    ],
                    'value' => $value === null ? null : [
                        'edge' => $value['edge'],
                        'pick' => $value['pick'],
                        'market' => $value['market'],
                        'odds' => $value['odds'],
                    ],
                ];
            });

        return Inertia::render('Dashboard', [
            'leagues' => League::query()
                ->withCount('teams')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'country']),
            'fixtures' => $fixtures,
        ]);
    }
}
