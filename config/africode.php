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

    // FBref via soccerdata — the statistical engine (corners, cards, shots, xG).
    'fbref' => [
        // Tracked seasons in soccerdata key format. Null (default) = rolling
        // window computed at runtime by App\Support\Seasons::tracked(), which
        // picks up each new season automatically in August. Pin explicitly
        // with FBREF_SEASONS=2324,2425,2526 if ever needed.
        'seasons' => env('FBREF_SEASONS') ? explode(',', env('FBREF_SEASONS')) : null,
        'script_path' => base_path('scripts/fbref_scrape.py'),
        'output_path' => storage_path('app/pipeline/fbref_latest.json'),
        // First full scrape of 3 seasons takes hours (polite throttling);
        // run it via `africode:scrape-fbref --now`. Nightly incremental runs
        // only fetch new pages and fit comfortably in this cap.
        'scrape_timeout_seconds' => 7200,

        // Player match stats: one FBref match-report request per fixture, so
        // the 3-season backfill runs as nightly batches (resumable via the
        // player_scrape_progress table). ~5,400 historical matches at the
        // default batch size drain in a few weeks while the app stays fully
        // usable; raise the batch for manual catch-up runs.
        'player_script_path' => base_path('scripts/fbref_scrape_players.py'),
        'player_output_path' => storage_path('app/pipeline/fbref_players_latest.json'),
        'player_batch_size' => (int) env('FBREF_PLAYER_BATCH_SIZE', 150),
        'player_scrape_timeout_seconds' => 5400,
        // Wrap browser-driving scrapes in `xvfb-run` (virtual display) when
        // available — required for headed Chrome on display-less servers.
        'xvfb' => (bool) env('FBREF_XVFB', true),
        // Proxy for FBref requests when Cloudflare blocks the server's own
        // IP. "tor" uses a local Tor daemon (apt install tor).
        'proxy' => env('FBREF_PROXY'),
    ],

    // Best Bet eligibility window (spec section 7.5).
    'best_bet' => [
        'min_prob' => (float) env('BEST_BET_MIN_PROB', 0.62),
        'max_prob' => (float) env('BEST_BET_MAX_PROB', 0.92),
    ],

    // Prediction generation (scripts/predict.py).
    'predict' => [
        'script_path' => base_path('scripts/predict.py'),
        'input_path' => storage_path('app/pipeline/predict_input.json'),
        'output_path' => storage_path('app/pipeline/predictions_latest.json'),
        'days_ahead' => 7,
        'timeout_seconds' => 600,
    ],

    // Daily accumulators built from model fair odds (1/probability).
    'accas' => [
        'tiers' => [3, 10, 20, 50, 100, 1000, 10000],
        // Leg eligibility band: floor keeps near-coin-flips out, ceiling
        // keeps trivial "over 0.5 goals" legs out (same ethos as Best Bets).
        'leg_min_prob' => 0.55,
        'leg_max_prob' => 0.92,
    ],

    // Optional single password protecting the whole app (Phase 1, no auth).
    'access_password' => env('APP_ACCESS_PASSWORD'),

    // Understat — free player match data + per-match team xG (plain HTTP,
    // reachable from datacenter IPs; the FBref-blocked fallback that keeps
    // the chatbot's player features and the goals model's xG blend alive).
    'understat' => [
        'script_path' => base_path('scripts/understat_scrape.py'),
        'output_path' => storage_path('app/pipeline/understat_latest.json'),
        // New roster fetches per nightly run (~1.2s each). The ~5,700-match
        // backfill drains in about two weeks at the default.
        'batch_size' => (int) env('UNDERSTAT_BATCH_SIZE', 400),
        'timeout_seconds' => 3600,
    ],

    // Gemini-powered chat assistant.
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models/',
        // Global daily cap on Gemini API requests (every tool round counts);
        // resets at midnight Africa/Lagos.
        'daily_cap' => (int) env('GEMINI_DAILY_CAP', 1000),
        'max_tool_rounds' => 5,
        'max_message_length' => 500,
        'history_messages' => 10,
        // Per-session/IP limits enforced with Laravel's RateLimiter.
        'per_minute' => 10,
        'per_day' => 60,
    ],

];
