# Africode Football AI — Build Specification (Handoff to Claude Code)

## 1. Project Overview

**Africode Football AI** is a data-driven football match prediction web app for personal use (Phase 1 — no admin panel, no user accounts, no subscriptions).

For every upcoming fixture in Europe's top 5 leagues (Premier League, La Liga, Serie A, Bundesliga, Ligue 1), the system:

1. Pulls together historical and current-season statistical data for both teams.
2. Runs per-market statistical models (goals, corners, cards, shots on target).
3. Calculates the probability of every standard betting line in each market.
4. Selects and displays the single **highest-confidence "Best Bet"** for the match (e.g. "Over 9.5 corners — 78%"), plus a ranked list of other likely outcomes across all markets.
5. Tracks its own accuracy per market over time so the models can be trusted (or fixed).

**Hard constraint: FREE TIERS ONLY.** All data sources below are free. The architecture is designed around free-tier rate limits.

---

## 2. Tech Stack

- **Backend:** Laravel 11+ (PHP 8.2+), MySQL, Laravel Queues (database driver), Laravel Scheduler (cron)
- **Frontend:** Inertia.js + Vue 3 + Tailwind CSS
- **Modeling/scraping sidecar:** Python 3.11+ with `soccerdata`, `pandas`, `numpy`, `scipy`. Python scripts are invoked by Laravel scheduled jobs via `Symfony\Component\Process` and exchange data through JSON files and/or direct MySQL writes (use `mysql-connector-python` or write JSON that a Laravel import job consumes — prefer JSON exchange for simplicity and safety).
- **Deployment target:** Ubuntu VPS with HestiaCP. Deployment method: zip upload to `public_html`, SQL import via phpMyAdmin, `.env` configuration. Do NOT assume git-based deployment. Ensure `php artisan queue:work` runs via supervisor or a cron-based `queue:work --stop-when-empty` loop, and `schedule:run` is in crontab every minute.

---

## 3. Data Sources (Free Tier Strategy)

### 3.1 The critical free-tier fact

**API-Football's free plan does NOT include the current season** (free plans are restricted to older seasons only — roughly 2021–2023). Therefore API-Football **cannot** be the primary source for current-season predictions on a free tier. It is optional/supplemental only.

The free-tier architecture is:

| Role | Source | Cost | Notes |
|---|---|---|---|
| Fixtures & results (current season, top 5 leagues) | **football-data.org v4 API** | Free | 10 req/min on free tier; top 5 leagues + more included |
| Match statistics — corners, cards, fouls, shots, shots on target, xG, possession (historical + current season) | **FBref via `soccerdata` Python library** | Free | Scraping; strict rate limits (~1 request per 3–6 s); `soccerdata` handles caching + polite throttling |
| Optional historical backfill / cross-check (older seasons only) | **API-Football (api-sports.io)** | Free | 100 req/day, 10 req/min, old seasons only on free plan |

### 3.2 football-data.org (fixtures + results)

- Base URL: `https://api.football-data.org/v4/`
- Auth: header `X-Auth-Token: {token}`
- Competition codes: `PL` (Premier League), `PD` (La Liga), `SA` (Serie A), `BL1` (Bundesliga), `FL1` (Ligue 1)
- Key endpoints:
  - `GET /v4/competitions/{code}/matches?dateFrom=&dateTo=` — fixtures & results in a date window
  - `GET /v4/competitions/{code}/matches?status=SCHEDULED` — upcoming
  - Match objects include home/away teams, kickoff UTC, status, score, matchday, and referees (when assigned).
- **Rate limit: 10 requests/minute.** With 5 leagues, a full daily sync is ~5–10 requests. Trivial. Add a 7-second sleep between requests anyway.
- football-data.org free tier does NOT provide corners/cards/shots — that is FBref's job.

### 3.3 FBref via soccerdata (the statistical engine)

- Python library: `pip install soccerdata pandas numpy scipy`
- League key in soccerdata: `"Big 5 European Leagues Combined"` covers all five leagues in one reader.
- Usage pattern:

```python
import soccerdata as sd

fbref = sd.FBref(leagues="Big 5 European Leagues Combined",
                 seasons=["2324", "2425", "2526"])

# Team-level match logs — one row per team per match:
schedule   = fbref.read_schedule()                     # fixtures, results, referee
shooting   = fbref.read_team_match_stats(stat_type="shooting")     # shots, SoT, xG
misc       = fbref.read_team_match_stats(stat_type="misc")         # corners (CK), fouls, cards (CrdY, CrdR)
passing    = fbref.read_team_match_stats(stat_type="passing_types") # crosses (a corner-count predictor)
keeper     = fbref.read_team_match_stats(stat_type="keeper")       # SoT against
```

- Relevant columns: `CK` (corner kicks), `CrdY`/`CrdR` (cards), `Fls`/`Fld` (fouls committed/drawn), `Sh`/`SoT` (shots / on target), `xG`/`xGA`, `Poss`, `Crs` (crosses). Referee name comes from the schedule table.
- **Rate limits / etiquette:** FBref blocks aggressive scrapers. `soccerdata` throttles and caches to `~/.soccerdata/` by default — keep caching ON. Run the scrape **once nightly**, never on user request. Wrap in retries with exponential backoff. Log failures; do not crash the pipeline.
- **Resilience requirement:** FBref scraping can break when their HTML changes. Build the pipeline so a failed scrape is non-fatal: the app keeps predicting from the last good data in MySQL and shows a "data as of {date}" freshness stamp in the UI.

### 3.4 API-Football (optional, supplemental)

- Base URL: `https://v3.football.api-sports.io/`
- Auth: header `x-apisports-key: {key}`
- Free plan: 100 requests/day, 10 requests/minute, **older seasons only** (no current season). Useful only for backfilling extra historical seasons of corners/cards via `GET /fixtures/statistics?fixture={id}` (1 request per fixture — a full league season ≈ 380 requests ≈ 4 days of quota). Implement as a low-priority queued backfill job that consumes ≤90 requests/day and resumes where it left off. Make the whole integration optional behind an env flag `APIFOOTBALL_ENABLED=false` by default.

---

## 4. How to Obtain the API Keys (instructions for the owner, John)

### football-data.org (required)
1. Go to `https://www.football-data.org/client/register`.
2. Register with name + email. No credit card.
3. The API token is emailed to you / shown in your account page.
4. Put it in `.env` as `FOOTBALLDATA_TOKEN=...`.
5. Free tier = 10 requests/minute, top competitions included (PL, PD, SA, BL1, FL1, CL and more).

### FBref (required — no key needed)
1. Nothing to register. `soccerdata` scrapes FBref directly.
2. Just install Python deps on the VPS: `pip install soccerdata pandas numpy scipy`.
3. First run will be slow (builds the local cache); subsequent nightly runs only fetch new/changed pages.

### API-Football / api-sports.io (optional)
1. Go to `https://dashboard.api-football.com/register` (register directly with api-sports — NOT via RapidAPI, direct registration gives cleaner limits).
2. Register with email. No credit card required for the free plan.
3. Copy the API key from the dashboard.
4. Put it in `.env` as `APIFOOTBALL_KEY=...` and set `APIFOOTBALL_ENABLED=true` only if using the historical backfill.
5. Remember: free plan = 100 req/day, 10 req/min, old seasons only. Quota resets 00:00 UTC.

---

## 5. Database Schema (MySQL)

```
leagues
  id, code (PL/PD/SA/BL1/FL1), name, country, fbref_id (nullable), apifootball_id (nullable)

teams
  id, league_id, name, fbref_name, footballdata_id, apifootball_id (nullable), short_name, logo_url (nullable)

referees
  id, name
  -- plus computed profile columns updated nightly:
  matches_officiated, avg_yellows_per_match, avg_reds_per_match, avg_fouls_per_match

fixtures
  id, league_id, season (e.g. "2025-2026"), matchday,
  home_team_id, away_team_id, kickoff_utc, status (scheduled|finished|postponed),
  referee_id (nullable), footballdata_match_id, 
  home_goals, away_goals (nullable until finished),
  is_derby (boolean, seeded from a static rivalry list per league)

match_stats            -- one row per TEAM per FINISHED fixture (source: FBref)
  id, fixture_id, team_id, is_home,
  goals, xg, xga, shots, shots_on_target, shots_on_target_against,
  corners_for, corners_against, crosses,
  fouls_committed, fouls_drawn, yellows, reds, possession,
  source (fbref|apifootball), created_at

team_profiles          -- rolling aggregates recomputed nightly, one row per team per season
  id, team_id, season, matches_played,
  -- exponentially weighted (recent matches weighted more; half-life ~8 matches),
  -- split home/away where relevant:
  attack_strength, defence_strength,          -- Dixon-Coles parameters
  xg_for_avg, xg_against_avg,
  corners_for_avg, corners_against_avg, crosses_avg,
  cards_avg, fouls_committed_avg, fouls_drawn_avg,
  sot_for_avg, sot_against_avg,
  home_advantage_factor

predictions            -- one row per fixture per generation run
  id, fixture_id, generated_at, model_version,
  best_bet_market, best_bet_line, best_bet_direction (over|under),
  best_bet_probability, headline_text

prediction_markets     -- every line evaluated for a prediction
  id, prediction_id,
  market (goals|corners|cards|shots_on_target|btts|team_goals_home|team_goals_away),
  line (e.g. 2.5, 9.5, 4.5), direction (over|under|yes|no),
  probability, confidence_margin (abs(probability - 0.5)),
  outcome (pending|won|lost|void), settled_at (nullable)

model_accuracy         -- materialized view / nightly-updated table
  market, line_bucket, total_settled, hits, hit_rate, avg_probability, calibration_gap
```

---

## 6. Data Pipeline (Laravel Scheduler + Queued Jobs)

All external calls run in **queued jobs** — never inline in web requests. (This VPS has previously suffered PHP-FPM worker exhaustion from blocking external API calls; do not repeat that.)

| Time (server TZ: Africa/Lagos) | Job | What it does |
|---|---|---|
| 02:00 nightly | `ScrapeFbrefJob` | Runs `python3 scripts/fbref_scrape.py`, which pulls latest team match logs + schedule for the 3 tracked seasons and writes `storage/app/pipeline/fbref_latest.json` |
| 02:45 nightly | `ImportFbrefDataJob` | Parses the JSON, upserts `match_stats`, `fixtures` (results), `referees` |
| 03:00 nightly | `SyncFixturesJob` | football-data.org: fixtures for next 14 days + results for last 3 days, all 5 leagues (7 s sleep between requests) |
| 03:15 nightly | `RecomputeProfilesJob` | Rebuilds `team_profiles` and referee profiles from `match_stats` (exponential weighting) |
| 03:30 nightly | `SettlePredictionsJob` | Marks `prediction_markets` won/lost using finished fixture stats; refreshes `model_accuracy` |
| 06:00 daily | `GeneratePredictionsJob` | Runs `python3 scripts/predict.py` for all fixtures in the next 7 days; writes predictions JSON; Laravel imports into `predictions` + `prediction_markets` |
| Optional, hourly, quota-aware | `ApiFootballBackfillJob` | Only if `APIFOOTBALL_ENABLED=true` |

Add a `pipeline_runs` log table (job name, started_at, finished_at, status, error) and surface the last-run status in the UI footer.

---

## 7. Prediction Models (implemented in Python, `scripts/predict.py`)

All models output a probability for each line. Version the model logic (`model_version` string) so accuracy can be compared across iterations.

### 7.1 Goals — Dixon-Coles adjusted Poisson
- Estimate per-team attack/defence strengths and a home-advantage term from the last ~2 seasons of data, weighted exponentially by recency (half-life ≈ 8 matches). **Use xG as the primary input signal blended with actual goals (e.g. 70% xG / 30% goals)** to reduce luck noise.
- Expected goals for home team: `λ_home = attack_home × defence_away × home_adv × league_avg`. Same shape for away.
- Apply the Dixon-Coles low-score correction (adjusts 0-0, 1-0, 0-1, 1-1 probabilities).
- From the joint score matrix (cap at 10 goals each), derive: Over/Under 0.5, 1.5, 2.5, 3.5, 4.5 total goals; BTTS yes/no; home team over/under 0.5, 1.5, 2.5; away same.

### 7.2 Corners — Negative Binomial
- Poisson underfits corner variance; use negative binomial. Expected total corners: `μ = f(home_corners_for_avg, away_corners_against_avg) + f(away_corners_for_avg, home_corners_against_avg)`, with an uplift term for style mismatch: high crosses-per-match and high possession vs low-block opponents inflate corner counts. Fit the dispersion parameter on league history.
- Lines: Over/Under 7.5, 8.5, 9.5, 10.5, 11.5 total corners; per-team corners 3.5, 4.5, 5.5.

### 7.3 Cards — Referee-centred negative binomial
- Expected total cards: `μ = w_ref × referee_avg_yellows + w_teams × (home_cards_avg + away_cards_avg + fouls interaction) + derby_uplift`. The referee term is the single biggest predictor — if the referee is unassigned (common until ~48h before kickoff), fall back to league-average referee and flag `referee_known=false` in the output (lower confidence).
- `is_derby` fixtures get a fitted uplift (seed the rivalry list statically: e.g. Arsenal–Spurs, Real–Barça/Atleti, Milan derby, Rome derby, Ruhr derby, PSG–OM, etc.).
- Lines: Over/Under 2.5, 3.5, 4.5, 5.5 total cards.

### 7.4 Shots on Target — Poisson regression
- Per team: expected SoT = f(team sot_for_avg, opponent sot_against_avg, home/away split). Lines: team over/under 2.5, 3.5, 4.5, 5.5 SoT; total match SoT 6.5, 7.5, 8.5.

### 7.5 Best Bet selection
1. Compute probability for **every** line in every market above.
2. `confidence_margin = |probability − 0.5|`.
3. Discard trivial picks: exclude any line whose probability > 0.92 (e.g. "over 0.5 corners") — they carry no betting value. Configurable ceiling `BEST_BET_MAX_PROB=0.92` and floor `BEST_BET_MIN_PROB=0.62`.
4. The eligible line with the highest confidence margin becomes the **Best Bet** headline. All other eligible lines are listed ranked by probability, grouped by market.
5. If referee is unknown, cards-market picks are penalized (multiply confidence margin by 0.8) so they rarely headline without referee data.

---

## 8. Web App (Inertia + Vue 3 + Tailwind) — Pages

No auth in Phase 1 (optionally protect the whole app with a single `.env` password + middleware, `APP_ACCESS_PASSWORD`, since it will be on a public VPS).

1. **Dashboard / Fixtures (`/`)** — upcoming fixtures grouped by date and league (league filter tabs). Each fixture card: teams, kickoff (Africa/Lagos), league badge, the Best Bet headline with probability, and a confidence indicator. Data-freshness stamp in footer.
2. **Match Detail (`/match/{fixture}`)** — full prediction breakdown: Best Bet hero block; then per-market sections (Goals, Corners, Cards, Shots on Target) each showing every line with probability bars; the model inputs panel (both teams' rolling stats, referee profile, derby flag, xG trend sparkline); and head-to-head recent meetings.
3. **Accuracy Tracker (`/accuracy`)** — per-market hit rate over time (last 30/90/all days), win rate of headline Best Bets specifically, calibration table (predicted probability bucket vs actual hit rate), and model_version comparison. This page is the truth-teller — build it early, not last.
4. **History (`/history`)** — settled predictions list with won/lost badges, filterable by market and league.

Design: dark, data-dense, modern sports-analytics aesthetic. Brand it **Africode Football AI** (navbar + a simple wordmark/logo placeholder). Mobile-first — primary usage will be on a phone.

---

## 9. Non-Functional Requirements

- **Everything external is queued.** No blocking API/scrape calls in HTTP request lifecycle, no unqueued cron HTTP calls.
- Respect rate limits religiously: 7 s sleep between football-data.org calls; soccerdata default throttling for FBref; quota counter table for API-Football.
- Cache computed predictions — the web app reads only from MySQL, never triggers computation.
- Graceful degradation: if FBref fails, keep serving last predictions with a freshness warning; if football-data.org fails, retry with backoff.
- Timezone: store UTC, display Africa/Lagos.
- Config via `.env`:

```
FOOTBALLDATA_TOKEN=
APIFOOTBALL_ENABLED=false
APIFOOTBALL_KEY=
PYTHON_BIN=python3
BEST_BET_MIN_PROB=0.62
BEST_BET_MAX_PROB=0.92
APP_ACCESS_PASSWORD=
```

- Provide a `README-DEPLOY.md` covering: zip-upload deployment to HestiaCP `public_html`, SQL import via phpMyAdmin, `.env` setup, installing Python deps on the VPS, crontab lines for `schedule:run` and queue worker, and the one-time historical seed command (`php artisan africode:seed-history` which triggers the initial FBref scrape of 3 seasons — expect this first run to take a long while due to polite scraping).

## 10. Build Order

1. Migrations + models + league/team/rivalry seeders.
2. football-data.org fixture sync (visible fixtures = quick win).
3. FBref scrape script + import job (3 seasons of Big 5 match logs).
4. Profile recomputation (team + referee rolling stats).
5. Python prediction models + generation job + import.
6. Frontend: dashboard → match detail → accuracy → history.
7. Settlement job + accuracy tracking.
8. Deployment docs + first live run.

**Definition of done for Phase 1:** every top-5-league fixture in the next 7 days shows a Best Bet with probability, all markets listed, and after one full week of matches the accuracy page correctly settles and scores every prediction.
