# Africode Football AI — Deployment Guide (HestiaCP, zip upload)

Deployment model: **zip upload to `public_html`**, SQL import via phpMyAdmin,
`.env` configuration by hand. No git on the server required.

## 0. Server requirements

- Ubuntu VPS with HestiaCP
- PHP 8.2+ (CLI + FPM) with extensions: `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `intl`
- MySQL / MariaDB
- Python 3.11+ with `pip`
- Cron access for the web user (Hestia: **User → Cron Jobs**)

## 1. Build the zip locally

On your local machine (needs PHP, Composer, Node):

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # compiles resources/ -> public/build/
```

Zip the project **including** `vendor/` and `public/build/`, excluding what the
server never needs:

```bash
zip -r africode.zip . \
  -x ".git/*" "node_modules/*" "tests/*" ".env" "storage/logs/*" \
     "storage/framework/cache/data/*" "storage/framework/sessions/*" "storage/framework/views/*"
```

## 2. Create the site in HestiaCP

1. **Web → Add Domain** (e.g. `football.example.com`).
2. Edit the domain → **Advanced Options → Custom document root** and point it
   at the app's `public/` folder, e.g. `/home/USER/web/football.example.com/public_html/public`.
   Laravel must never be served from the project root — only `public/` is web-facing.
3. Enable SSL (**Let's Encrypt** checkbox).

Upload `africode.zip` into `public_html` (Hestia File Manager or SFTP) and
unzip it there, so `artisan` sits at `public_html/artisan`.

## 3. Database

1. **DB → Add Database** in Hestia (e.g. `africode`), note user + password.
2. Schema + seed data, either way:
   - **With SSH (preferred):**
     ```bash
     cd ~/web/football.example.com/public_html
     php artisan migrate --seed --force
     ```
   - **phpMyAdmin only:** on your local machine, point `.env` at a scratch
     MySQL database, run `php artisan migrate --seed --force`, export it with
     `mysqldump africode_local > africode.sql`, then import `africode.sql`
     through the server's phpMyAdmin.

The seeders load the 5 leagues, all 96 current-season teams, and the
derby/rivalry list.

## 4. Configure `.env`

Copy `.env.example` to `.env` in `public_html` and set:

```dotenv
APP_NAME="Africode Football AI"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://football.example.com
APP_KEY=                       # then run: php artisan key:generate --force

DB_DATABASE=africode
DB_USERNAME=...
DB_PASSWORD=...

QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=7800      # must exceed the 2h FBref scrape timeout

# football-data.org token (register: https://www.football-data.org/client/register)
FOOTBALLDATA_TOKEN=...

# Optional API-Football historical backfill — leave disabled unless used
APIFOOTBALL_ENABLED=false
APIFOOTBALL_KEY=

PYTHON_BIN=python3
BEST_BET_MIN_PROB=0.62
BEST_BET_MAX_PROB=0.92

# Optional: single password protecting the whole app (HTTP Basic, any username)
APP_ACCESS_PASSWORD=...
```

Then (SSH) finish up:

```bash
php artisan key:generate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache
chmod -R ug+rw storage bootstrap/cache
```

No SSH? Generate `APP_KEY` locally (`php artisan key:generate --show`) and
paste it, and skip the caches — the app works uncached.

## 5. Python dependencies (FBref scraping + models)

```bash
python3 -m pip install --user -r scripts/requirements.txt
```

`soccerdata` caches scraped pages under `~/.soccerdata/` — leave that in place,
it is what keeps nightly scrapes fast and polite.

## 6. Cron: scheduler + queue worker

All external API calls and scrapes run in queued jobs — the two crontab lines
below are the entire runtime. In Hestia (**User → Cron Jobs**) add, adjusting
the path:

```cron
* * * * * cd /home/USER/web/football.example.com/public_html && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USER/web/football.example.com/public_html && /usr/bin/flock -n storage/framework/queue.lock php artisan queue:work --stop-when-empty --timeout=7800 --tries=1 >> storage/logs/queue.log 2>&1
```

The `flock` guard makes the worker loop safe: a new worker starts each minute
only if the previous one has finished, and `--stop-when-empty` lets it exit
when the queue drains. If you prefer supervisor, run
`php artisan queue:work --timeout=7800 --tries=1` under it instead and drop the
second cron line.

## 7. First run — seed three seasons of history

```bash
php artisan africode:sync-fixtures --now    # fixtures for the next 14 days (~40s)
php artisan africode:seed-history           # FBref scrape + import + profiles + predictions
```

**Expect `seed-history` to take a long while the first time** (hours):
soccerdata scrapes FBref politely, one throttled request at a time, across
three seasons of five leagues. Run it inside `screen`/`tmux` or let it ride.
Every later nightly scrape is incremental and takes minutes.

When it finishes, the dashboard shows every upcoming fixture with a Best Bet.

## 8. The nightly pipeline (all times Africa/Lagos)

| Time  | Job                    | What it does |
|-------|------------------------|--------------|
| 02:00 | `ScrapeFbrefJob`       | FBref match logs → `storage/app/pipeline/fbref_latest.json` |
| 02:45 | `ImportFbrefDataJob`   | JSON → `match_stats`, results, referees |
| 03:00 | `SyncFixturesJob`      | football-data.org: next 14 days + last 3 days (7s between requests) |
| 03:15 | `RecomputeProfilesJob` | team + referee rolling profiles |
| 03:30 | `SettlePredictionsJob` | scores pending picks, refreshes `model_accuracy` |
| 06:00 | `GeneratePredictionsJob` | runs the models for the next 7 days of fixtures |

## 9. Troubleshooting

- **Footer freshness stamps** on every page show when each data source last
  succeeded — that is the first thing to check.
- The `pipeline_runs` table logs every job run with status and error message:
  `SELECT * FROM pipeline_runs ORDER BY id DESC LIMIT 20;`
- A failed FBref scrape is designed to be non-fatal: the app keeps predicting
  from the last good data. Check `storage/logs/laravel.log` and re-run
  `php artisan africode:scrape-fbref --now`.
- football-data.org 403/429: check `FOOTBALLDATA_TOKEN`; the free tier allows
  10 requests/minute and the sync sleeps 7s between calls, so quota errors
  normally mean a bad token.
- After editing `.env`, run `php artisan config:cache` again (or delete
  `bootstrap/cache/config.php`).

## 10. Updating the app

Upload a fresh zip (minus `.env` and `storage/`), unzip over the old code,
then `php artisan migrate --force && php artisan config:cache`. Migrations are
additive; the database keeps its history.
