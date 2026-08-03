<?php

namespace App\Http\Controllers;

use App\Models\Accumulator;
use App\Models\AccumulatorLeg;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AccumulatorsController extends Controller
{
    /**
     * The latest generated accumulator set, split into its two families —
     * classic target-odds tickets, and banker tickets grouped by how much a
     * single leg is allowed to pay — plus the all-time record of each.
     */
    public function __invoke(): Response
    {
        $displayTz = config('africode.display_timezone');
        $latestGeneratedAt = Accumulator::max('generated_at');

        $latest = $latestGeneratedAt === null
            ? collect()
            : Accumulator::where('generated_at', $latestGeneratedAt)
                ->with([
                    'legs.fixture:id,kickoff_utc,kickoff_confirmed,home_team_id,away_team_id',
                    'legs.fixture.homeTeam:id,short_name',
                    'legs.fixture.awayTeam:id,short_name',
                ])
                ->get()
                ->keyBy(fn (Accumulator $acca) => $this->key($acca->family, $acca->max_leg_odds, $acca->target_odds));

        $record = $this->record();
        $banker = config('africode.accas.banker');

        $families = [
            [
                'key' => Accumulator::FAMILY_CLASSIC,
                'label' => 'Classic',
                'blurb' => 'Any price per leg, so a few strong calls carry the ticket. Short and punchy.',
                'groups' => [[
                    'label' => null,
                    'hint' => null,
                    'tickets' => $this->tickets(
                        $latest, Accumulator::FAMILY_CLASSIC, null, config('africode.accas.tiers'),
                    ),
                ]],
                'record' => $record->where('family', Accumulator::FAMILY_CLASSIC)->values(),
            ],
            [
                'key' => Accumulator::FAMILY_BANKER,
                'label' => 'Banker',
                'blurb' => 'Big totals built only from short, high-probability legs — no single result carries the ticket, but it takes a lot of them.',
                'groups' => collect($banker['caps'])->map(fn ($cap) => [
                    'label' => 'Max '.number_format((float) $cap, 2).' per leg',
                    'hint' => 'Every leg is a '.round(100 / (float) $cap).'%+ call.',
                    'tickets' => $this->tickets(
                        $latest, Accumulator::FAMILY_BANKER, (float) $cap, $banker['targets'],
                    ),
                ])->values()->all(),
                'record' => $record->where('family', Accumulator::FAMILY_BANKER)->values(),
            ],
        ];

        return Inertia::render('Accumulators', [
            'generated_at' => $latestGeneratedAt !== null
                ? Carbon::parse($latestGeneratedAt)->timezone($displayTz)->isoFormat('D MMM, HH:mm')
                : null,
            'families' => $families,
            'max_legs' => (int) $banker['max_legs'],
        ]);
    }

    /**
     * One card per configured tier, whether or not it was built today.
     *
     * @param  Collection<string, Accumulator>  $latest
     * @param  list<int|string>  $targets
     * @return list<array<string, mixed>>
     */
    private function tickets(Collection $latest, string $family, ?float $maxLegOdds, array $targets): array
    {
        return collect($targets)->map(function ($target) use ($latest, $family, $maxLegOdds) {
            /** @var Accumulator|null $accumulator */
            $accumulator = $latest->get($this->key($family, $maxLegOdds, (int) $target));

            return [
                'target' => (int) $target,
                'max_leg_odds' => $maxLegOdds,
                'available' => $accumulator !== null,
                'combined_odds' => $accumulator?->combined_odds,
                'combined_probability' => $accumulator?->combined_probability,
                'outcome' => $accumulator?->outcome,
                'legs' => $accumulator?->legs->map(fn (AccumulatorLeg $leg) => [
                    'match' => $leg->fixture->homeTeam->short_name.' v '.$leg->fixture->awayTeam->short_name,
                    'fixture_id' => $leg->fixture_id,
                    'kickoff' => $leg->fixture->kickoffLabel(),
                    'market' => $leg->market,
                    'line' => $leg->line,
                    'direction' => $leg->direction,
                    'probability' => $leg->probability,
                    'odds' => $leg->odds,
                ])->values(),
            ];
        })->values()->all();
    }

    /**
     * All-time won/lost per ticket definition.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function record(): Collection
    {
        return Accumulator::query()
            ->whereIn('outcome', [Accumulator::OUTCOME_WON, Accumulator::OUTCOME_LOST])
            ->groupBy('family', 'max_leg_odds', 'target_odds')
            ->selectRaw('family, max_leg_odds, target_odds')
            ->selectRaw("SUM(CASE WHEN outcome = 'won' THEN 1 ELSE 0 END) as won")
            ->selectRaw('COUNT(*) as total')
            ->orderBy('max_leg_odds')
            ->orderBy('target_odds')
            ->get()
            ->map(fn ($row) => [
                'family' => $row->family,
                'max_leg_odds' => $row->max_leg_odds === null ? null : (float) $row->max_leg_odds,
                'target' => (int) $row->target_odds,
                'won' => (int) $row->won,
                'total' => (int) $row->total,
            ]);
    }

    private function key(string $family, ?float $maxLegOdds, int $target): string
    {
        return $family.'|'.($maxLegOdds === null ? '' : number_format($maxLegOdds, 2)).'|'.$target;
    }
}
