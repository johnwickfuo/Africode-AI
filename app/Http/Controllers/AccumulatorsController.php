<?php

namespace App\Http\Controllers;

use App\Models\Accumulator;
use App\Models\PipelineRun;
use App\Support\AccumulatorPresenter;
use Illuminate\Database\Eloquent\Builder;
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
     *
     * Only tickets that can still be backed appear here. Once enough of a
     * ticket has kicked off it retires: it leaves this page for the
     * Accuracy page, and its slot is filled by a fresh ticket on the next
     * build.
     */
    public function __invoke(): Response
    {
        $displayTz = config('africode.display_timezone');

        // Driven by what is outstanding, not by the newest build: a ticket
        // is carried across builds until it retires.
        $live = $this->newestPerDefinition(Accumulator::query()->live());

        $lastRun = PipelineRun::lastSuccessfulRun('GenerateAccumulatorsJob')?->finished_at
            ?? Accumulator::max('generated_at');

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
                        $live, Accumulator::FAMILY_CLASSIC, null, config('africode.accas.tiers'),
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
                    // Deliberately not "an 80%+ call": the cap is now on the
                    // price a book would pay, which already carries margin,
                    // so it maps to a lower probability than 1/cap.
                    'hint' => 'Nothing on the ticket pays more than '.number_format((float) $cap, 2).'.',
                    'tickets' => $this->tickets(
                        $live, Accumulator::FAMILY_BANKER, (float) $cap, $banker['targets'],
                    ),
                ])->values()->all(),
                'record' => $record->where('family', Accumulator::FAMILY_BANKER)->values(),
            ],
        ];

        return Inertia::render('Accumulators', [
            'generated_at' => $lastRun !== null
                ? Carbon::parse($lastRun)->timezone($displayTz)->isoFormat('D MMM, HH:mm')
                : null,
            'families' => $families,
            'max_legs' => (int) $banker['max_legs'],
        ]);
    }

    /**
     * One card per configured tier, holding whatever is outstanding for it.
     * A retired ticket is never shown here — it has been replaced, or is
     * waiting to be on the next build.
     *
     * @param  Collection<string, Accumulator>  $live
     * @param  list<int|string>  $targets
     * @return list<array<string, mixed>>
     */
    private function tickets(Collection $live, string $family, ?float $maxLegOdds, array $targets): array
    {
        return collect($targets)->map(function ($target) use ($live, $family, $maxLegOdds) {
            $outstanding = $live->get(Accumulator::keyFor($family, $maxLegOdds, (int) $target));

            return $outstanding === null
                ? [
                    'target' => (int) $target,
                    'max_leg_odds' => $maxLegOdds,
                    'available' => false,
                    'legs' => [],
                ]
                : AccumulatorPresenter::present($outstanding);
        })->values()->all();
    }

    /**
     * The newest ticket per definition, so a definition never shows twice.
     *
     * @param  Builder<Accumulator>  $query
     * @return Collection<string, Accumulator>
     */
    private function newestPerDefinition(Builder $query): Collection
    {
        return $query
            ->with(AccumulatorPresenter::relations())
            ->orderByDesc('generated_at')
            ->get()
            ->groupBy(fn (Accumulator $acca) => $acca->definitionKey())
            ->map->first();
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
}
