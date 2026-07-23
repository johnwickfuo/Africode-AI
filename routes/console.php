<?php

use App\Jobs\GenerateAccumulatorsJob;
use App\Jobs\GeneratePredictionsJob;
use App\Jobs\ImportCsvStatsJob;
use App\Jobs\ImportFbrefDataJob;
use App\Jobs\ImportOddsJob;
use App\Jobs\RecomputeProfilesJob;
use App\Jobs\ScrapeFbrefJob;
use App\Jobs\ScrapePlayerStatsJob;
use App\Jobs\ScrapeUnderstatJob;
use App\Jobs\SettlePredictionsJob;
use App\Jobs\SyncFixturesJob;
use Illuminate\Support\Facades\Schedule;

// Data pipeline (spec section 6). Times are Africa/Lagos server-local times;
// each scheduled entry only queues a job — external calls run on the queue.
Schedule::job(new ScrapeFbrefJob)
    ->dailyAt('02:00')
    ->timezone('Africa/Lagos');

Schedule::job(new ImportFbrefDataJob)
    ->dailyAt('02:45')
    ->timezone('Africa/Lagos');

// Free CSV stats (corners/cards/shots/referees) — works from any IP and
// keeps stats flowing even while FBref is Cloudflare-blocked.
Schedule::job(new ImportCsvStatsJob)
    ->dailyAt('02:30')
    ->timezone('Africa/Lagos');

Schedule::job(new SyncFixturesJob)
    ->dailyAt('03:00')
    ->timezone('Africa/Lagos');

Schedule::job(new RecomputeProfilesJob)
    ->dailyAt('03:15')
    ->timezone('Africa/Lagos');

Schedule::job(new SettlePredictionsJob)
    ->dailyAt('03:30')
    ->timezone('Africa/Lagos');

// Free player data + per-match team xG from understat (plain HTTP, any
// IP): one batch per night, newest matches first, until the 3-season
// backfill completes.
Schedule::job(new ScrapeUnderstatJob)
    ->dailyAt('04:00')
    ->timezone('Africa/Lagos');

// FBref player match stats (adds shots-on-target on top of understat).
// Only useful when FBref is reachable, so gated on FBREF_PROXY.
Schedule::job(new ScrapePlayerStatsJob)
    ->dailyAt('04:30')
    ->timezone('Africa/Lagos')
    ->when(fn () => filled(config('africode.fbref.proxy')));

// Bookmaker odds for upcoming fixtures (free fixtures.csv) — fetched just
// before predictions so the value-bet comparison uses fresh prices.
Schedule::job(new ImportOddsJob)
    ->dailyAt('05:45')
    ->timezone('Africa/Lagos');

Schedule::job(new GeneratePredictionsJob)
    ->dailyAt('06:00')
    ->timezone('Africa/Lagos');

Schedule::job(new GenerateAccumulatorsJob)
    ->dailyAt('06:30')
    ->timezone('Africa/Lagos');
