<?php

namespace App\Http\Controllers;

use App\Models\Accumulator;
use App\Models\AccumulatorLeg;
use Inertia\Inertia;
use Inertia\Response;

class AccumulatorsController extends Controller
{
    /**
     * The latest generated accumulator set (one card per tier, unavailable
     * tiers flagged) plus the all-time won/lost record per tier.
     */
    public function __invoke(): Response
    {
        $displayTz = config('africode.display_timezone');
        $latestGeneratedAt = Accumulator::max('generated_at');

        $latest = $latestGeneratedAt === null
            ? collect()
            : Accumulator::where('generated_at', $latestGeneratedAt)
                ->with([
                    'legs.fixture:id,kickoff_utc,home_team_id,away_team_id',
                    'legs.fixture.homeTeam:id,short_name',
                    'legs.fixture.awayTeam:id,short_name',
                ])
                ->orderBy('target_odds')
                ->get()
                ->keyBy('target_odds');

        $tiers = collect(config('africode.accas.tiers'))->map(function (int $target) use ($latest, $displayTz) {
            /** @var Accumulator|null $accumulator */
            $accumulator = $latest->get($target);

            return [
                'target' => $target,
                'available' => $accumulator !== null,
                'combined_odds' => $accumulator?->combined_odds,
                'combined_probability' => $accumulator?->combined_probability,
                'outcome' => $accumulator?->outcome,
                'legs' => $accumulator?->legs->map(fn (AccumulatorLeg $leg) => [
                    'match' => $leg->fixture->homeTeam->short_name.' v '.$leg->fixture->awayTeam->short_name,
                    'fixture_id' => $leg->fixture_id,
                    'kickoff' => $leg->fixture->kickoff_utc->timezone($displayTz)->isoFormat('ddd D MMM, HH:mm'),
                    'market' => $leg->market,
                    'line' => $leg->line,
                    'direction' => $leg->direction,
                    'probability' => $leg->probability,
                    'odds' => $leg->odds,
                ])->values(),
            ];
        })->values();

        $record = Accumulator::query()
            ->whereIn('outcome', [Accumulator::OUTCOME_WON, Accumulator::OUTCOME_LOST])
            ->groupBy('target_odds')
            ->selectRaw('target_odds')
            ->selectRaw("SUM(CASE WHEN outcome = 'won' THEN 1 ELSE 0 END) as won")
            ->selectRaw('COUNT(*) as total')
            ->orderBy('target_odds')
            ->get()
            ->map(fn ($row) => [
                'target' => (int) $row->target_odds,
                'won' => (int) $row->won,
                'total' => (int) $row->total,
            ]);

        return Inertia::render('Accumulators', [
            'generated_at' => $latestGeneratedAt !== null
                ? \Illuminate\Support\Carbon::parse($latestGeneratedAt)->timezone($displayTz)->isoFormat('D MMM, HH:mm')
                : null,
            'tiers' => $tiers,
            'record' => $record,
        ]);
    }
}
