<?php

namespace App\Support;

use App\Models\Accumulator;
use App\Models\AccumulatorLeg;

/**
 * The shape a ticket takes on the front end. Shared because a ticket is
 * rendered by the same component in two places: on the Accumulators page
 * while it can still be backed, and on the Accuracy page once it has run.
 */
class AccumulatorPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Accumulator $accumulator): array
    {
        return [
            'id' => $accumulator->id,
            'family' => $accumulator->family,
            'target' => (int) $accumulator->target_odds,
            'max_leg_odds' => $accumulator->max_leg_odds,
            'available' => true,
            'started' => false,
            'window' => $accumulator->windowLabel(),
            'combined_odds' => $accumulator->combined_odds,
            'model_combined_odds' => $accumulator->model_combined_odds,
            'combined_probability' => $accumulator->combined_probability,
            'outcome' => $accumulator->outcome,
            'legs' => $accumulator->legs->map(fn (AccumulatorLeg $leg) => [
                // Full names: "MID v LIN" is unreadable on a slip, and a
                // ticket is meant to be copied into a bookmaker's app.
                'match' => $leg->fixture->homeTeam->name.' v '.$leg->fixture->awayTeam->name,
                // A ticket mixes a dozen divisions, and short names alone
                // ("MID v LIN") do not say where to find the match.
                'league' => $leg->fixture->league->code,
                'league_name' => $leg->fixture->league->name,
                'fixture_id' => $leg->fixture_id,
                'kickoff' => $leg->fixture->kickoffLabel(),
                'market' => $leg->market,
                'line' => $leg->line,
                'direction' => $leg->direction,
                'probability' => $leg->probability,
                'odds' => $leg->odds,
                'model_odds' => $leg->model_odds,
            ])->values(),
        ];
    }

    /**
     * Eager loads exactly what present() reads.
     *
     * @return list<string>
     */
    public static function relations(): array
    {
        return [
            'legs.fixture:id,league_id,kickoff_utc,kickoff_confirmed,home_team_id,away_team_id',
            'legs.fixture.league:id,code,name',
            'legs.fixture.homeTeam:id,name',
            'legs.fixture.awayTeam:id,name',
        ];
    }
}
