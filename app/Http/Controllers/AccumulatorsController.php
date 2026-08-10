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
     * Only tickets that can still be backed appear here. Once a ticket's
     * last match kicks off it belongs to the record, not the shelf, and
     * moves to the Accuracy page.
     */
    public function __invoke(): Response
    {
        $displayTz = config('africode.display_timezone');

        // A ticket stands until its last match kicks off, so the page is
        // driven by what is outstanding rather than by the newest build.
        $live = $this->newestPerDefinition(Accumulator::query()->live());

        // For a definition with nothing live, the ticket that just ran —
        // so the card can say it kicked off instead of "never built".
        $started = $this->newestPerDefinition(
            Accumulator::query()->started()->where('generated_at', '>=', now()->subDays(7)),
        );

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
                        $live, $started, Accumulator::FAMILY_CLASSIC, null, config('africode.accas.tiers'),
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
                        $live, $started, Accumulator::FAMILY_BANKER, (float) $cap, $banker['targets'],
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
     * One card per configured tier: the outstanding ticket if there is one,
     * otherwise a flag for the ticket that has just run. Saying "not
     * available" for a ticket that was built and played would be wrong.
     *
     * @param  Collection<string, Accumulator>  $live
     * @param  Collection<string, Accumulator>  $started
     * @param  list<int|string>  $targets
     * @return list<array<string, mixed>>
     */
    private function tickets(
        Collection $live,
        Collection $started,
        string $family,
        ?float $maxLegOdds,
        array $targets,
    ): array {
        return collect($targets)->map(function ($target) use ($live, $started, $family, $maxLegOdds) {
            $key = Accumulator::keyFor($family, $maxLegOdds, (int) $target);

            if ($outstanding = $live->get($key)) {
                return AccumulatorPresenter::present($outstanding);
            }

            /** @var Accumulator|null $ran */
            $ran = $started->get($key);

            return [
                'target' => (int) $target,
                'max_leg_odds' => $maxLegOdds,
                'available' => false,
                'started' => $ran !== null,
                'outcome' => $ran?->outcome,
                'legs' => [],
            ];
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
