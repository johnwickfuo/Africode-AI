<?php

namespace App\Http\Controllers;

use App\Models\Fixture;
use App\Models\League;
use App\Models\PipelineRun;
use App\Services\FootballData\FixtureSyncService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Upcoming fixtures for the next 14 days, grouped client-side by date.
     * Reads only from MySQL — computation and API calls happen in the
     * nightly pipeline, never here.
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
            ])
            ->get()
            ->map(fn (Fixture $fixture) => [
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
            ]);

        return Inertia::render('Dashboard', [
            'leagues' => League::query()
                ->withCount('teams')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'country']),
            'fixtures' => $fixtures,
            'lastSyncedAt' => PipelineRun::lastSuccessfulRun('SyncFixturesJob')
                ?->finished_at?->timezone($displayTz)->isoFormat('D MMM YYYY, HH:mm'),
        ]);
    }
}
