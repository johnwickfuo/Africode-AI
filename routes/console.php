<?php

use App\Jobs\GenerateAccumulatorsJob;
use App\Jobs\GeneratePredictionsJob;
use App\Jobs\ImportFbrefDataJob;
use App\Jobs\RecomputeProfilesJob;
use App\Jobs\ScrapeFbrefJob;
use App\Jobs\ScrapePlayerStatsJob;
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

Schedule::job(new SyncFixturesJob)
    ->dailyAt('03:00')
    ->timezone('Africa/Lagos');

Schedule::job(new RecomputeProfilesJob)
    ->dailyAt('03:15')
    ->timezone('Africa/Lagos');

Schedule::job(new SettlePredictionsJob)
    ->dailyAt('03:30')
    ->timezone('Africa/Lagos');

// One batch per night: covers new matches first (newest kickoffs first)
// and keeps draining the 3-season historical backfill until complete.
Schedule::job(new ScrapePlayerStatsJob)
    ->dailyAt('04:00')
    ->timezone('Africa/Lagos');

Schedule::job(new GeneratePredictionsJob)
    ->dailyAt('06:00')
    ->timezone('Africa/Lagos');

Schedule::job(new GenerateAccumulatorsJob)
    ->dailyAt('06:30')
    ->timezone('Africa/Lagos');
