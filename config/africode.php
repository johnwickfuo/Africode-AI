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

    // Nightly settlement.
    'settle' => [
        // Corners, cards and shots-on-target can only be scored once the
        // match stats land, which is a night or two behind the result and
        // sometimes never (FBref blocked, a division football-data.co.uk
        // does not publish). Past this many days after kickoff a pick that
        // still has no actual is voided rather than left pending forever —
        // an unscoreable leg drops out of its ticket the way a bookmaker
        // voids one, and voids never count towards the accuracy record.
        'grace_days' => (int) env('SETTLE_GRACE_DAYS', 3),
    ],

    // Bookmaker odds (football-data.co.uk fixtures.csv) and value bets.
    'odds' => [
        // Minimum model-vs-market probability edge for a "value" flag.
        'value_edge_threshold' => (float) env('VALUE_EDGE_THRESHOLD', 0.05),

        // What a mainstream book keeps on each market, as an overround on
        // the fair price: a pick we rate at 80% (fair 1.25) is offered at
        // 1/(0.80 x 1.08) = 1.16 on a market carrying 8%.
        //
        // Used only where nobody publishes a real price — 1X2 and the 2.5
        // goals line come from football-data.co.uk instead. The going rate
        // is tighter on the headline markets and wider on the ones books
        // treat as a sideshow, which is most of what a ticket is built
        // from. Raise these if slips keep coming back shorter than the
        // site said; they are the single dial for that.
        'margin' => [
            'result' => 0.05,
            'goals' => 0.06,
            'btts' => 0.07,
            'team_goals_home' => 0.08,
            'team_goals_away' => 0.08,
            'corners' => 0.09,
            'cards' => 0.09,
            'default' => 0.08,
        ],
    ],

    // Prior profile for clubs with no history in the data (promoted sides).
    // Values are a typical newly-promoted team in a top-5 league: weaker
    // attack, leakier defence, otherwise league-average. Blended out as
    // real matches accumulate: weight = matches / (matches + blend_matches).
    'priors' => [
        'blend_matches' => 6,
        'promoted' => [
            'attack_strength' => 0.85,
            'defence_strength' => 1.15,
            'home_advantage_factor' => 1.20,
            'xg_for_avg' => 1.05,
            'xg_against_avg' => 1.55,
            'corners_for_avg' => 4.6,
            'corners_against_avg' => 5.6,
            'crosses_avg' => 16.0,
            'cards_avg' => 2.2,
            'fouls_committed_avg' => 11.5,
            'fouls_drawn_avg' => 10.5,
            'sot_for_avg' => 3.9,
            'sot_against_avg' => 5.0,
        ],
    ],

    // A pick is only worth headlining if a punter can actually place it.
    // Mainstream books (SportyBet, Bet9ja, 1xBet, MSport) reliably price
    // match result, goals, BTTS, corners, cards and team goals; shots on
    // target — team-level especially — is largely a Bet365/Pinnacle market,
    // so those rows stay visible on the match page but never headline a
    // fixture or fill an accumulator leg.
    'markets' => [
        'bettable' => [
            'result',
            'goals',
            'btts',
            'corners',
            'cards',
            'team_goals_home',
            'team_goals_away',
        ],
        // Lines this low pay ~1.05 and read as filler even when available.
        'min_headline_line' => 1.5,
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
        'tiers' => [3, 5, 10, 20, 50, 100, 1000, 10000],
        // Leg eligibility band: floor keeps near-coin-flips out, ceiling
        // keeps trivial "over 0.5 goals" legs out (same ethos as Best Bets).
        'leg_min_prob' => 0.55,
        'leg_max_prob' => 0.92,

        // And a floor on what a leg must actually pay. The probability
        // ceiling above is in model terms; once margin is taken a 92% pick
        // is offered at about 1.01, which is all of the risk for none of
        // the return. Priced in bookmaker terms this is the honest limit.
        'leg_min_odds' => 1.05,

        // Every ticket's legs must fall inside this many consecutive
        // calendar days (display timezone), so a ticket settles as one
        // weekend rather than dribbling out over a fortnight. Each ticket
        // takes the earliest window it can complete in.
        'window_days' => 2,

        // A ticket retires once this share of its legs has kicked off: it
        // leaves the accumulators page and its definition is free for a
        // fresh ticket. Waiting for the last leg would leave a ticket
        // nobody can back sitting on the shelf for most of a weekend.
        'retire_at_started_share' => 0.30,

        // "Banker" tickets: a big total built only from short, high-
        // probability legs, so no single result carries the ticket. Each
        // cap is the most a single leg may pay — 1.25 means every leg is
        // an 80%+ call — and each is offered at four target totals.
        //
        // The arithmetic is unforgiving at the top: 20x from 1.25 legs needs
        // at least 14 of them and realistically closer to 20, one per
        // fixture, so the tighter caps only fill their big targets on a busy
        // weekend. The short targets are easy by comparison — 3x out of 1.25
        // legs is five picks — and give the same safe-leg idea at a stake
        // most people would actually place. Tiers the card cannot reach are
        // shown as unavailable rather than padded out.
        'banker' => [
            'caps' => [1.25, 1.40, 1.60],
            'targets' => [3, 5, 10, 20, 40, 80, 160],
            'max_legs' => 25,
        ],
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
        'model' => env('GEMINI_MODEL', 'gemini-flash-latest'),
        // Used (once) when the primary model is overloaded, rate-limited,
        // or stalls. Must be a model the key has FREE-TIER quota for —
        // older pinned models (gemini-2.0-*) report "limit: 0" on new keys.
        'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-flash-lite-latest'),
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
