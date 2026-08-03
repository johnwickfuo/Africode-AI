# Africode Football AI — Deployment Guide (HestiaCP + git pull)

Deployment model: the site directory **is a clone of this repo** —
`git pull` is the whole deploy. `vendor/` and the compiled frontend
(`public/build/`) are committed, so the server needs **no Composer and no
Node**. The database is imported once from `database/africode.sql`; cron
jobs are added manually in HestiaCP.

## 0. Server requirements

- Ubuntu VPS with HestiaCP, SSH access as the site user (`admin`)
- PHP 8.2+ (CLI + FPM) with extensions: `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `intl`
- MySQL / MariaDB + phpMyAdmin (bundled with Hestia)
- `git` on the server
- Python 3.11+ with `pip`
- **Google Chrome + Xvfb** — soccerdata 1.9+ scrapes FBref through a real
  browser (FBref is behind Cloudflare). Cloudflare rejects *headless*
  Chrome ("failed CAPTCHA, IP block..."), so the scraper runs the browser
  headed inside an Xvfb virtual display. Install both once as root:

  ```bash
  wget -q https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
  apt install -y ./google-chrome-stable_current_amd64.deb xvfb
  ```

  The matching chromedriver downloads automatically on first scrape, and
  the scrape jobs wrap themselves in `xvfb-run` automatically whenever the
  binary is present (installing `xvfb` on a display-less server isn't
  enough on its own — seleniumbase silently falls back to headless without
  a display, and Cloudflare blocks headless).

## 1. Connect the site to the repo

The target directory `/home/admin/web/africodeai.online/public_html` must be
empty (Hestia pre-creates index.html — delete the contents first):

```bash
cd /home/admin/web/africodeai.online
rm -rf public_html/* public_html/.[!.]*
git clone --branch claude/laravel-inertia-setup-1davog \
    https://github.com/johnwickfuo/Africode-AI.git public_html
```

If the repo is private, create a GitHub **fine-grained personal access token**
(repo → Contents: read) and clone with
`https://<TOKEN>@github.com/johnwickfuo/Africode-AI.git`, or add the server's
SSH key as a deploy key on the repo and use the SSH URL. The token/key only
needs read access.

**Ownership matters.** PHP-FPM and the cron jobs run as the site user
(`admin`), which must be able to write `storage/`. If you cloned as root,
hand the tree over and do all later git/artisan commands as `admin`:

```bash
chown -R admin:admin /home/admin/web/africodeai.online/public_html
sudo -u admin git -C /home/admin/web/africodeai.online/public_html pull
```

(If you must run git as a different user than the files' owner, git refuses
with "dubious ownership" — fix with
`git config --global --add safe.directory /home/admin/web/africodeai.online/public_html`,
or simply run git as `admin` as shown above.)

## 2. Point the web root at public/

In HestiaCP: **Web → africodeai.online → Edit → Advanced Options →
Custom document root** → set it to:

```
/home/admin/web/africodeai.online/public_html/public
```

Save, and enable SSL (Let's Encrypt checkbox) while you're there. Only
Laravel's `public/` folder is ever web-served; the repo root (with `.env`,
`.git`, `storage/`) stays unreachable.

## 3. Database — one manual import

1. **DB → Add Database** in Hestia (e.g. `admin_africode`), note the user
   and password Hestia generates.
2. Open phpMyAdmin, select the new database, **Import** →
   `database/africode.sql` from the repo. That loads the full schema plus
   the seed data: 12 leagues, their squads, the derby list, and the
   `migrations` bookkeeping so future `php artisan migrate` knows what
   already ran.

### Tracked leagues

| League | Fixtures | Stats + odds | Notes |
|---|---|---|---|
| Premier League, La Liga, Serie A, Bundesliga, Ligue 1 | football-data.org (14 days) | football-data.co.uk | xG from Understat |
| Championship, Eredivisie, Primeira Liga | football-data.org (14 days) | football-data.co.uk | no xG |
| League One, League Two, Scottish Premiership, Süper Lig | fixturedownload.com (full season) | football-data.co.uk | no xG; not on the free API tier |

The free football-data.org tier carries 13 competitions, so four of the
tracked divisions have no API fixtures. They used to depend on
football-data.co.uk's `fixtures.csv`, which is a **~3-day rolling
window** — a league whose season had not started yet showed nothing at
all. They now take their schedule from fixturedownload.com, a free
keyless CSV of the complete published season, so every league carries the
same horizon. `fixtures.csv` still prices them.

Referees are published for the English and Scottish divisions only;
elsewhere the cards model falls back to the league-average referee and
lowers its confidence accordingly. Adding another league is a data
change: give it a row in `LeagueSeeder` with an `fdcouk_code` (see
football-data.co.uk for the division letter) and, if the free API tier
carries it, a `footballdata_code`. If it does not, add a `calendar_slug`
— the slug fixturedownload.com uses in its download URL, e.g.
`super-lig` for `.../download/super-lig-2026-UTC.csv`.

## 4. Configure .env

```bash
cd /home/admin/web/africodeai.online/public_html
cp .env.example .env
nano .env
```

Fill in:

```dotenv
APP_NAME="Africode Football AI"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://africodeai.online

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=admin_africode
DB_USERNAME=admin_africode
DB_PASSWORD=<from Hestia>

QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=7800        # must exceed the 2h FBref scrape timeout

FOOTBALLDATA_TOKEN=<your token>  # register: https://www.football-data.org/client/register
APIFOOTBALL_ENABLED=false

PYTHON_BIN=/home/admin/africode-venv/bin/python3   # the venv from section 5
FBREF_PLAYER_BATCH_SIZE=150
BEST_BET_MIN_PROB=0.62
BEST_BET_MAX_PROB=0.92

APP_ACCESS_PASSWORD=<optional site password>
GEMINI_API_KEY=<optional, for the chat assistant>
GEMINI_DAILY_CAP=1000
```

Then:

```bash
php artisan key:generate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache
chmod -R ug+rw storage bootstrap/cache
```

## 5. Python dependencies (FBref scraping + prediction models)

Ubuntu 24.04 ships no pip and marks the system Python "externally managed"
(PEP 668), so use a virtualenv and point the app at it:

```bash
apt update && apt install -y python3-pip python3-venv     # as root, once
sudo -u admin python3 -m venv /home/admin/africode-venv
sudo -u admin /home/admin/africode-venv/bin/pip install \
    -r /home/admin/web/africodeai.online/public_html/scripts/requirements.txt
```

Then in `.env` set:

```dotenv
PYTHON_BIN=/home/admin/africode-venv/bin/python3
```

and re-run `php artisan config:cache`. If a package fails to build a wheel,
`apt install -y build-essential python3-dev` and retry.

`soccerdata` caches under `~/.soccerdata/` (the admin user's home) — leave
it; that cache is what keeps nightly scrapes fast and polite.

## 6. Cron jobs — add manually in HestiaCP

**User → Cron Jobs → Add Cron Job**, both on the `admin` user. Two jobs,
both running **every minute** (minute `*`, hour `*`, day `*`, month `*`,
weekday `*`):

Job 1 — Laravel scheduler (fires the whole nightly pipeline):

```
cd /home/admin/web/africodeai.online/public_html && php artisan schedule:run >> /dev/null 2>&1
```

Job 2 — queue worker (processes the queued jobs; flock stops overlaps):

```
cd /home/admin/web/africodeai.online/public_html && /usr/bin/flock -n storage/framework/queue.lock php artisan queue:work --stop-when-empty --timeout=7800 --tries=1 >> storage/logs/queue.log 2>&1
```

That is the entire runtime — the scheduler queues jobs at their configured
times and the worker loop drains them.

## 7. First run — pull three seasons of history

The fast, works-from-anywhere path uses football-data.co.uk's free CSVs
(results + corners, cards, fouls, shots, SoT, referees — everything the
models need except xG/crosses):

```bash
cd /home/admin/web/africodeai.online/public_html
sudo -u admin php artisan africode:import-csv-stats --now      # 15 small CSVs, ~1 minute
sudo -u admin php artisan africode:scrape-understat --now --batch=800   # player data + xG, ~20 min per run
sudo -u admin php artisan africode:recompute-profiles --now    # team/referee profiles
sudo -u admin php artisan africode:sync-fixtures --now         # fixtures for the next 14 days
sudo -u admin php artisan africode:import-fixture-calendar --now  # full season for the four non-API leagues
sudo -u admin php artisan africode:generate-predictions --now  # predictions, if fixtures exist
```

Repeat the `scrape-understat` command (each run fetches the next batch of
match rosters, newest first, cached on disk) until it reports 0 rosters
pending — or just let the nightly 04:00 job drain the backfill over ~2
weeks. The app is fully usable throughout.

Optionally enrich with FBref (adds xG, crosses, possession, and player
stats) via `africode:seed-history` — but note Cloudflare blocks FBref for
many datacenter IPs ("failed CAPTCHA / IP block"). In that case either set
`FBREF_PROXY` to a residential proxy URL, or run `scripts/fbref_scrape.py`
on a home computer and upload the JSON to `storage/app/pipeline/` before
`africode:import-fbref --now`. The FBref scrape takes hours (polite
scraping); run it inside `screen`/`tmux`. Player match stats backfill
separately in nightly batches (section 9) and also need FBref access.

## 8. Deploying updates

```bash
cd /home/admin/web/africodeai.online/public_html
git pull
php artisan migrate --force        # applies any new migrations (no-op otherwise)
php artisan config:cache && php artisan route:cache
```

`vendor/` and `public/build/` update with the pull — nothing to build.
`database/africode.sql` in the repo is only for **fresh installs**; a live
database is updated by `php artisan migrate --force`, never by re-importing
the file.

## 9. The nightly pipeline (all times Africa/Lagos)

| Time  | Job                      | What it does |
|-------|--------------------------|--------------|
| 02:00 | `ScrapeFbrefJob`         | FBref team match logs → `storage/app/pipeline/fbref_latest.json` |
| 02:30 | `ImportCsvStatsJob`      | football-data.co.uk CSVs → results, corners/cards/shots stats, referees (never overwrites FBref rows) |
| 02:45 | `ImportFbrefDataJob`     | JSON → `match_stats`, results, referees |
| 02:50 | `ImportFixtureCalendarJob` | fixturedownload.com: full published season for the leagues the free API tier omits |
| 03:00 | `SyncFixturesJob`        | football-data.org: next 14 days + last 3 days (7s between requests) |
| 03:15 | `RecomputeProfilesJob`   | team + referee rolling profiles |
| 03:30 | `SettlePredictionsJob`   | scores pending picks, refreshes `model_accuracy` |
| 04:00 | `ScrapeUnderstatJob`     | understat.com player match data + per-match team xG (free, any IP; one batch/night, newest first) |
| 04:30 | `ScrapePlayerStatsJob`   | FBref player stats (adds shots-on-target) — only runs when `FBREF_PROXY` is set |
| 05:45 | `ImportOddsJob`          | bookmaker odds for upcoming fixtures (free fixtures.csv) — powers the value-bet comparison |
| 06:00 | `GeneratePredictionsJob` | runs the champion model (all markets incl. 1X2) and the ML challenger for the next 7 days of fixtures |
| 06:30 | `GenerateAccumulatorsJob` | builds the daily accumulator set: classic 3x-10000x plus the banker tickets |

### Accumulator families

Two sets are built each morning, from the same pool of picks but with
separate ledgers, so a classic and a banker ticket may land on the same
call while no two tickets inside one family ever do.

| Family | Tickets | Rule |
|---|---|---|
| Classic | 3x, 10x, 20x, 50x, 100x, 1000x, 10000x | any leg price; a few long calls carry the total |
| Banker | 20x, 40x, 80x, 160x at each of three caps | no leg longer than 1.25, 1.40 or 1.60 |

A banker cap is a ceiling on what one leg may pay, so 1.25 means every leg
is an 80%+ call. That forces long tickets — 20x out of 1.25 legs needs at
least 14 of them, one per fixture — so the tighter rows only fill on a
busy card. Simulated against a realistic weekend the whole grid builds
except 1.25/160x; on a 15-fixture midweek card about half of it does.
Tiers the card cannot reach are shown as unavailable rather than padded
with weaker legs. Tuning lives in `config/africode.php` under
`accas.banker` (`caps`, `targets`, `max_legs`, default 25).

### Player-stats backfill

Player data needs one FBref match-report request per fixture, so the
3-season history backfills in nightly batches (default 150 matches,
`FBREF_PLAYER_BATCH_SIZE`) tracked in `player_scrape_progress` — roughly
5,400 matches ≈ a few weeks at the default pace. The app is fully usable
throughout. To go faster:

```bash
php artisan africode:scrape-players --now --batch=400   # repeat as desired
```

`SELECT status, COUNT(*) FROM player_scrape_progress GROUP BY status;`
shows progress.

## 10. Troubleshooting

- **Footer freshness stamps** on every page show when each data source last
  succeeded — check there first.
- `pipeline_runs` logs every job with status + error:
  `SELECT * FROM pipeline_runs ORDER BY id DESC LIMIT 20;`
- A failed FBref scrape is non-fatal by design — the app keeps predicting
  from the last good data. Re-run with `php artisan africode:scrape-fbref --now`.
- football-data.org 403/429 usually means a bad `FOOTBALLDATA_TOKEN` (the
  sync already respects the 10 req/min limit).
- Chat usage/quota: `SELECT status, COUNT(*), SUM(gemini_requests) FROM
  chat_logs GROUP BY status;`
- **Chatbot replies "Something went wrong" + `Chat exchange failed ... 404`
  in `storage/logs/laravel.log`**: Google retires pinned Gemini models for
  new API keys (e.g. `gemini-2.5-flash` returns *"no longer available to
  new users"*). Keep `GEMINI_MODEL=gemini-flash-latest` (the rolling alias)
  in `.env` and re-run `php artisan config:cache`.
- **`Call to undefined function ...pcntl_signal()` repeating in
  `storage/logs/queue.log`**: HestiaCP ships CLI PHP with the `pcntl_*`
  functions in `disable_functions`, which kills `queue:work` instantly —
  so no queued job (the entire nightly pipeline) ever runs. As root, edit
  `/etc/php/8.3/cli/php.ini` (match your PHP version), remove every
  `pcntl_*` entry from the `disable_functions` line, then verify with
  `php -r "var_dump(function_exists('pcntl_signal'));"` → `bool(true)`.
  Only the CLI ini is involved; leave the FPM ini alone.
- After editing `.env`: `php artisan config:cache` again.
