<?php

return [

    // Kickoffs are stored UTC; the UI renders them in this timezone.
    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Africa/Lagos'),

    // football-data.org v4 — fixtures & results. Free tier: 10 req/min,
    // so pipeline jobs sleep 7 s between requests.
    'footballdata' => [
        'token' => env('FOOTBALLDATA_TOKEN'),
        'base_url' => 'https://api.football-data.org/v4/',
        'request_sleep_seconds' => 7,
    ],

    // API-Football (api-sports.io) — optional historical backfill only.
    // Free plan: 100 req/day, 10 req/min, older seasons only.
    'apifootball' => [
        'enabled' => env('APIFOOTBALL_ENABLED', false),
        'key' => env('APIFOOTBALL_KEY'),
        'base_url' => 'https://v3.football.api-sports.io/',
        'daily_request_budget' => 90,
    ],

    // Python sidecar (FBref scraping + prediction models).
    'python_bin' => env('PYTHON_BIN', 'python3'),

    // Best Bet eligibility window (spec section 7.5).
    'best_bet' => [
        'min_prob' => (float) env('BEST_BET_MIN_PROB', 0.62),
        'max_prob' => (float) env('BEST_BET_MAX_PROB', 0.92),
    ],

    // Optional single password protecting the whole app (Phase 1, no auth).
    'access_password' => env('APP_ACCESS_PASSWORD'),

];
