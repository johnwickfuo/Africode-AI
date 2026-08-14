<?php

namespace App\Http\Controllers;

use App\Models\Fixture;
use App\Models\League;
use App\Models\Prediction;
use App\Services\FootballData\FixtureSyncService;
use App\Services\Odds\ValueBets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * How many fixtures to preview for a league whose season starts beyond
     * the main window — enough to show the opening weekend's shape without
     * burying the leagues that are actually playing.
     */
    private const PREVIEW_FIXTURES = 5;

    public function __construct(private ValueBets $valueBets) {}

    /**
     * Upcoming fixtures grouped by league, each with its latest Best Bet.
     *
     * The main window is the next 14 days. A league with nothing in it —
     * because its season has not started yet — still gets a section, showing
     * the first few fixtures of its campaign, so no tracked league ever
     * silently disappears from the site. Reads only from the database.
     */
    public function __invoke(): Response
    {
        $leagues = League::orderBy('name')->get(['id', 'code', 'name', 'country']);

        $inWindow = $this->load(
            Fixture::upcoming()->where(
                'kickoff_utc', '<=', now('UTC')->addDays(FixtureSyncService::DAYS_AHEAD),
            ),
        );

        $byLeague = $inWindow->groupBy('league_id');

        $groups = $leagues
            ->map(function (League $league) use ($byLeague) {
                $fixtures = $byLeague->get($league->id);

                // Nothing this fortnight: preview the start of its season.
                $preview = $fixtures === null;
                if ($preview) {
                    $fixtures = $this->load(
                        Fixture::upcoming()->where('league_id', $league->id)->limit(self::PREVIEW_FIXTURES),
                    );

                    if ($fixtures->isEmpty()) {
                        return null;
                    }
                }

                $first = $fixtures->first();

                return [
                    'league' => $league->only(['code', 'name', 'country']),
                    'preview' => $preview,
                    'starts_in_days' => $preview
                        ? (int) ceil(now('UTC')->diffInDays($first->kickoff_utc, absolute: true))
                        : null,
                    'first_kickoff' => $first->kickoff_utc->toIso8601String(),
                    'fixtures' => $fixtures->map(fn (Fixture $fixture) => $this->present($fixture))->values(),
                ];
            })
            ->filter()
            // Leagues in play first, soonest kick-off leading; the ones still
            // waiting on their opener fall to the bottom in the same order.
            ->sortBy(fn (array $group) => ($group['preview'] ? '1' : '0').$group['first_kickoff'])
            ->values();

        return Inertia::render('Dashboard', [
            'groups' => $groups,
            'dates' => $this->dates($inWindow),
            'window_days' => FixtureSyncService::DAYS_AHEAD,
        ]);
    }

    /**
     * The match days inside the window, for the date filter. Labelled here
     * rather than in the browser so "Today" means today where the fixtures
     * are listed, not wherever the visitor happens to be.
     *
     * @param  Collection<int, Fixture>  $fixtures
     * @return list<array<string, mixed>>
     */
    private function dates(Collection $fixtures): array
    {
        $displayTz = config('africode.display_timezone');
        $today = now($displayTz)->startOfDay();

        return $fixtures
            ->groupBy(fn (Fixture $fixture) => $fixture->kickoff_utc->timezone($displayTz)->toDateString())
            ->map(function (Collection $onDay, string $key) use ($displayTz, $today) {
                $date = $onDay->first()->kickoff_utc->timezone($displayTz)->startOfDay();
                $daysAway = (int) $today->diffInDays($date, absolute: false);

                return [
                    'key' => $key,
                    'label' => match ($daysAway) {
                        0 => 'Today',
                        1 => 'Tomorrow',
                        default => $date->isoFormat('ddd D MMM'),
                    },
                    // Weekday alone is ambiguous past a week out.
                    'sublabel' => $daysAway <= 1 ? $date->isoFormat('ddd D MMM') : null,
                    'count' => $onDay->count(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Fixture>  $query
     * @return Collection<int, Fixture>
     */
    private function load($query): Collection
    {
        return $query->with([
            'league:id,code,name',
            'homeTeam:id,name,short_name,logo_url',
            'awayTeam:id,name,short_name,logo_url',
            'odds',
            'predictions' => fn ($predictions) => $predictions->champion()->orderByDesc('generated_at'),
            'predictions.markets',
        ])->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Fixture $fixture): array
    {
        $displayTz = config('africode.display_timezone');

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
            'kickoff_date' => $fixture->kickoff_utc->timezone($displayTz)->isoFormat('ddd D MMM'),
            'date_key' => $fixture->kickoff_utc->timezone($displayTz)->toDateString(),
            'kickoff_time' => $fixture->kickoff_confirmed
                ? $fixture->kickoff_utc->timezone($displayTz)->format('H:i')
                : 'TBC',
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
    }
}
