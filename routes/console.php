<?php

use App\Jobs\SyncFixturesJob;
use Illuminate\Support\Facades\Schedule;

// Data pipeline (spec section 6). Times are Africa/Lagos server-local times;
// each scheduled entry only queues a job — external calls run on the queue.
Schedule::job(new SyncFixturesJob)
    ->dailyAt('03:00')
    ->timezone('Africa/Lagos');
