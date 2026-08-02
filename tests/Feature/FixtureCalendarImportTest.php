<?php

namespace Tests\Feature;

use App\Jobs\ImportFixtureCalendarJob;
use App\Models\Fixture;
use App\Models\League;
use App\Models\PipelineRun;
use App\Models\Team;
use App\Services\Fixtures\FixtureCalendarImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FixtureCalendarImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'Match Number,Round Number,Date,Location,Home Team,Away Team,Result';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        // Mid-season, so only one season's calendar is looked up.
        Carbon::setTestNow('2026-10-01 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, list<string>>  $calendars  league slug => CSV rows
     */
    private function fakeCalendars(array $calendars): void
    {
        $fakes = [];
        foreach ($calendars as $slug => $rows) {
            $fakes["fixturedownload.com/download/{$slug}-2026-UTC.csv"] = Http::response(
                "\u{FEFF}".self::HEADER."\n".implode("\n", $rows)
            );
        }
        // Everything else — including a season nobody has published yet.
        $fakes['fixturedownload.com/*'] = Http::response('Not found', 404);

        Http::fake($fakes);
    }

    public function test_publishes_a_full_season_of_fixtures_for_a_non_api_league(): void
    {
        $this->fakeCalendars(['super-lig' => [
            '1,1,15/08/2026 21:00,Gaziantep Stadyumu,Gaziantep,Alanyaspor,',
            '2,1,15/08/2026 18:30,Rams Park,Galatasaray,Fenerbahce,',
            // Deep into the season: this is what fixtures.csv could never show.
            '250,30,17/04/2027 18:00,Sukru Saracoglu,Fenerbahce,Galatasaray,',
        ]]);

        $summary = app(FixtureCalendarImportService::class)->run();

        $this->assertSame(3, $summary['fixtures_created']);
        $this->assertSame(3, $summary['rows_seen']);

        $league = League::where('code', 'TSL')->first();
        $fixtures = Fixture::where('league_id', $league->id)->orderBy('kickoff_utc')->get();
        $this->assertCount(3, $fixtures);

        $opener = $fixtures->first();
        $this->assertSame('2026-2027', $opener->season);
        $this->assertSame('2026-08-15 18:30', $opener->kickoff_utc->format('Y-m-d H:i'));
        $this->assertSame(1, $opener->matchday);
        $this->assertSame(Fixture::STATUS_SCHEDULED, $opener->status);
        $this->assertSame('Galatasaray', $opener->homeTeam->name);
        $this->assertSame('Fenerbahçe', $opener->awayTeam->name);

        // Seven months of horizon, where fixtures.csv gives about three days.
        $this->assertSame('2027-04-17', $fixtures->last()->kickoff_utc->toDateString());
    }

    public function test_api_covered_leagues_are_left_to_the_api(): void
    {
        $this->fakeCalendars([]);

        app(FixtureCalendarImportService::class)->run();

        // Only the four leagues off the free API tier opt in; the rest keep
        // football-data.org as the single owner of their schedule.
        $this->assertEqualsCanonicalizing(
            ['EL1', 'EL2', 'SPL', 'TSL'],
            League::whereNotNull('calendar_slug')->pluck('code')->all(),
        );

        Http::assertSentCount(4);
        foreach (['efl-league-one', 'efl-league-two', 'scottish-premiership', 'super-lig'] as $slug) {
            Http::assertSent(fn ($request) => $request->url()
                === "https://fixturedownload.com/download/{$slug}-2026-UTC.csv");
        }
        $this->assertSame(0, Fixture::count());
    }

    public function test_a_fixture_the_odds_import_already_created_is_updated_not_duplicated(): void
    {
        $league = League::where('code', 'SPL')->first();
        $celtic = Team::where('name', 'Celtic')->firstOrFail();
        $rangers = Team::where('name', 'Rangers')->firstOrFail();

        // fixtures.csv creates rows with a day-precision kickoff and no round.
        $existing = Fixture::create([
            'league_id' => $league->id,
            'season' => '2026-2027',
            'home_team_id' => $celtic->id,
            'away_team_id' => $rangers->id,
            'kickoff_utc' => '2026-10-04 15:00:00',
            'status' => Fixture::STATUS_SCHEDULED,
        ]);

        $this->fakeCalendars(['scottish-premiership' => [
            '80,9,04/10/2026 12:00,Celtic Park,Celtic,Rangers,',
        ]]);

        $summary = app(FixtureCalendarImportService::class)->run();

        $this->assertSame(0, $summary['fixtures_created']);
        $this->assertSame(1, $summary['fixtures_updated']);
        $this->assertSame(1, Fixture::count());

        $existing->refresh();
        $this->assertSame('2026-10-04 12:00', $existing->kickoff_utc->format('Y-m-d H:i'));
        $this->assertSame(9, $existing->matchday);
    }

    public function test_repeat_pairings_in_a_split_season_stay_separate_fixtures(): void
    {
        // A 12-team league plays the same pairing more than once, so the
        // calendar must not collapse both meetings onto one row.
        $this->fakeCalendars(['scottish-premiership' => [
            '80,9,04/10/2026 12:00,Celtic Park,Celtic,Rangers,',
            '190,33,02/05/2027 12:00,Celtic Park,Celtic,Rangers,',
        ]]);

        app(FixtureCalendarImportService::class)->run();

        $this->assertSame(2, Fixture::count());
        $this->assertEqualsCanonicalizing(
            [9, 33],
            Fixture::pluck('matchday')->all(),
        );
    }

    public function test_a_played_result_is_filled_in_but_never_overwritten(): void
    {
        $league = League::where('code', 'SPL')->first();
        $hearts = Team::where('name', 'Heart of Midlothian')->firstOrFail();
        $hibs = Team::where('name', 'Hibernian')->firstOrFail();

        $settled = Fixture::create([
            'league_id' => $league->id,
            'season' => '2026-2027',
            'matchday' => 3,
            'home_team_id' => $hearts->id,
            'away_team_id' => $hibs->id,
            'kickoff_utc' => '2026-08-29 14:00:00',
            'status' => Fixture::STATUS_FINISHED,
            'home_goals' => 2,
            'away_goals' => 0,
        ]);

        $this->fakeCalendars(['scottish-premiership' => [
            // Disagrees with the stored score, and reschedules a finished
            // match — both must be ignored.
            '30,3,29/08/2026 14:00,Tynecastle,Heart of Midlothian,Hibernian,9 - 9',
            // Never seen before, already played: worth recording.
            '31,4,12/09/2026 14:00,Easter Road,Hibernian,Heart of Midlothian,1 - 3',
        ]]);

        $summary = app(FixtureCalendarImportService::class)->run();

        $settled->refresh();
        $this->assertSame(2, $settled->home_goals);
        $this->assertSame(0, $settled->away_goals);

        $this->assertSame(1, $summary['results_filled']);
        $derby = Fixture::where('home_team_id', $hibs->id)->firstOrFail();
        $this->assertSame(Fixture::STATUS_FINISHED, $derby->status);
        $this->assertSame(1, $derby->home_goals);
        $this->assertSame(3, $derby->away_goals);
    }

    public function test_a_placeholder_kickoff_time_is_marked_unconfirmed(): void
    {
        // The Süper Lig calendar publishes real dates but 21:00 for every
        // single match, so the time must not be presented as fact.
        $rows = [];
        foreach (range(1, 24) as $i) {
            $rows[] = "{$i},1,15/08/2026 21:00,Stadium,Galatasaray,Fenerbahce,";
        }
        $this->fakeCalendars([
            'super-lig' => $rows,
            'scottish-premiership' => [
                '1,1,01/08/2026 14:00,Celtic Park,Celtic,Rangers,',
                '2,1,01/08/2026 16:30,Ibrox,Hibernian,Aberdeen,',
            ],
        ]);

        app(FixtureCalendarImportService::class)->run();

        $turkish = Fixture::whereHas('league', fn ($q) => $q->where('code', 'TSL'))->first();
        $this->assertFalse($turkish->kickoff_confirmed);
        $this->assertStringEndsWith(', TBC', $turkish->kickoffLabel());

        // A calendar with a real spread of slots is trusted as published.
        $scottish = Fixture::whereHas('league', fn ($q) => $q->where('code', 'SPL'))->first();
        $this->assertTrue($scottish->kickoff_confirmed);
        $this->assertStringEndsWith(', 15:00', $scottish->kickoffLabel());
    }

    public function test_a_promoted_club_missing_from_the_seed_is_created(): void
    {
        $this->fakeCalendars(['super-lig' => [
            '1,1,15/08/2026 21:00,Amed Arena,Amedspor,Galatasaray,',
        ]]);

        $summary = app(FixtureCalendarImportService::class)->run();

        $this->assertSame(1, $summary['teams_created']);
        $this->assertSame(1, $summary['fixtures_created']);

        $created = Team::where('name', 'Amedspor')->firstOrFail();
        $this->assertSame('AME', $created->short_name);
        $this->assertSame(League::where('code', 'TSL')->value('id'), $created->league_id);
    }

    public function test_an_unpublished_calendar_is_logged_and_skipped(): void
    {
        $this->fakeCalendars([]);

        $summary = app(FixtureCalendarImportService::class)->run();

        $this->assertSame(4, $summary['leagues']);
        $this->assertSame(4, $summary['calendars_missing']);
        $this->assertSame(0, $summary['fixtures_created']);
    }

    public function test_job_and_command_wiring(): void
    {
        $this->fakeCalendars([]);

        ImportFixtureCalendarJob::dispatchSync();
        $this->assertSame(
            PipelineRun::STATUS_SUCCESS,
            PipelineRun::lastSuccessfulRun('ImportFixtureCalendarJob')->status,
        );

        Queue::fake();
        $this->artisan('africode:import-fixture-calendar')->assertSuccessful();
        Queue::assertPushed(ImportFixtureCalendarJob::class);
    }
}
