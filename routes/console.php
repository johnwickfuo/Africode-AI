<?php

use App\Jobs\GeneratePredictionsJob;
use App\Jobs\ImportFbrefDataJob;
use App\Jobs\RecomputeProfilesJob;
use App\Jobs\ScrapeFbrefJob;
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

Schedule::job(new GeneratePredictionsJob)
    ->dailyAt('06:00')
    ->timezone('Africa/Lagos');
