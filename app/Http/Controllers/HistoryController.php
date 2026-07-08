<?php

namespace App\Http\Controllers;

use App\Models\League;
use App\Models\PredictionMarket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    /**
     * Settled prediction rows with won/lost badges, filterable by market
     * and league (spec 8.4).
     */
    public function __invoke(Request $request): Response
    {
        $market = $request->query('market');
        $leagueCode = $request->query('league');
        $displayTz = config('africode.display_timezone');

        $rows = PredictionMarket::query()
            ->whereIn('outcome', [PredictionMarket::OUTCOME_WON, PredictionMarket::OUTCOME_LOST])
            ->when($market, fn ($query) => $query->where('market', $market))
            ->when($leagueCode, fn ($query) => $query->whereHas(
                'prediction.fixture.league',
                fn ($league) => $league->where('code', $leagueCode),
            ))
            ->with([
                'prediction:id,fixture_id,model_version',
                'prediction.fixture:id,league_id,home_team_id,away_team_id,kickoff_utc,home_goals,away_goals',
                'prediction.fixture.league:id,code',
                'prediction.fixture.homeTeam:id,short_name',
                'prediction.fixture.awayTeam:id,short_name',
            ])
            ->orderByDesc('settled_at')
            ->paginate(50)
            ->withQueryString()
            ->through(function (PredictionMarket $row) use ($displayTz) {
                $fixture = $row->prediction->fixture;

                return [
                    'id' => $row->id,
                    'league' => $fixture->league->code,
                    'match' => sprintf(
                        '%s %d–%d %s',
                        $fixture->homeTeam->short_name,
                        $fixture->home_goals,
                        $fixture->away_goals,
                        $fixture->awayTeam->short_name,
                    ),
                    'fixture_id' => $fixture->id,
                    'market' => $row->market,
                    'line' => $row->line !== null ? (float) $row->line : null,
                    'direction' => $row->direction,
                    'probability' => (float) $row->probability,
                    'outcome' => $row->outcome,
                    'settled_at' => $row->settled_at?->timezone($displayTz)->isoFormat('D MMM YYYY'),
                ];
            });

        return Inertia::render('History', [
            'rows' => $rows,
            'filters' => [
                'market' => $market,
                'league' => $leagueCode,
            ],
            'markets' => PredictionMarket::query()->distinct()->orderBy('market')->pluck('market'),
            'leagues' => League::orderBy('name')->get(['code', 'name']),
        ]);
    }
}
