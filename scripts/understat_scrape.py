#!/usr/bin/env python3
"""Understat scraper for Africode Football AI.

Free player-level match data + per-match team xG for the top-5 leagues,
fetched from understat.com's JSON endpoints with plain HTTP requests — no
browser, no Cloudflare, works from datacenter IPs (unlike FBref).

Per league+season, one request returns every match (with team xG); one
request per match returns the player roster (minutes, goals, assists,
shots, xG, xA, cards). Roster responses are cached on disk, so the
multi-thousand-match historical backfill resumes for free: each nightly
run fetches at most --max-match-fetches new rosters (newest matches
first) and re-emits everything already cached.

Usage:
    python3 understat_scrape.py --output understat_latest.json \
        --seasons 2324 2425 2526 --max-match-fetches 400
"""

from __future__ import annotations

import argparse
import json
import logging
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import requests

BASE = "https://understat.com"
LEAGUES = {"PL": "EPL", "PD": "La_liga", "SA": "Serie_A", "BL1": "Bundesliga", "FL1": "Ligue_1"}
HEADERS = {
    "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36",
    "X-Requested-With": "XMLHttpRequest",
    "Accept": "application/json",
}
FETCH_SLEEP_SECONDS = 1.2

log = logging.getLogger("understat_scrape")


def season_year(season_key):
    """'2324' -> 2023 (understat uses the season's starting year)."""
    return 2000 + int(season_key[:2])


def get_json(session, path, retries=3):
    for attempt in range(1, retries + 1):
        try:
            response = session.get(f"{BASE}/{path}", headers=HEADERS, timeout=30)
            response.raise_for_status()
            return response.json()
        except Exception:
            log.exception("GET %s failed (attempt %d/%d)", path, attempt, retries)
            if attempt < retries:
                time.sleep(5 * attempt)
    return None


def number(value):
    try:
        f = float(value)
    except (TypeError, ValueError):
        return None
    return int(f) if f.is_integer() else round(f, 3)


def parse_roster(rosters):
    """Player rows from a getMatchData rosters payload; unused subs skipped."""
    players = []
    for side in ("h", "a"):
        entries = rosters.get(side) or {}
        rows = entries.values() if isinstance(entries, dict) else entries
        for row in rows:
            minutes = number(row.get("time")) or 0
            if minutes <= 0:
                continue
            players.append({
                "name": (row.get("player") or "").strip(),
                "side": "home" if side == "h" else "away",
                "position": (row.get("position") or None),
                "minutes": minutes,
                "goals": number(row.get("goals")),
                "assists": number(row.get("assists")),
                "shots": number(row.get("shots")),
                "xg": number(row.get("xG")),
                "xa": number(row.get("xA")),
                "yellows": number(row.get("yellow_card")),
                "reds": number(row.get("red_card")),
            })
    return [p for p in players if p["name"]]


def main():
    parser = argparse.ArgumentParser(description="Scrape understat.com match + player data to JSON.")
    parser.add_argument("--output", required=True)
    parser.add_argument("--seasons", nargs="+", default=["2324", "2425", "2526"])
    parser.add_argument("--max-match-fetches", type=int, default=400,
                        help="New roster fetches per run; cached rosters are always included")
    parser.add_argument("--cache-dir", default=str(Path.home() / ".understat_cache"))
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, stream=sys.stderr,
                        format="%(asctime)s %(levelname)s %(name)s: %(message)s")

    cache_dir = Path(args.cache_dir)
    cache_dir.mkdir(parents=True, exist_ok=True)

    session = requests.Session()
    matches_out = []
    budget = args.max_match_fetches
    rosters_missing = 0

    # Newest season first, newest matches first — current form lands first.
    for season in sorted(args.seasons, reverse=True):
        year = season_year(season)
        for code, understat_league in LEAGUES.items():
            data = get_json(session, f"getLeagueData/{understat_league}/{year}")
            if data is None:
                log.error("League data failed for %s %s — skipping file", code, year)
                continue

            finished = [m for m in data.get("dates", []) if m.get("isResult")]
            finished.sort(key=lambda m: m.get("datetime") or "", reverse=True)
            log.info("%s %d: %d finished matches", code, year, len(finished))

            for match in finished:
                match_id = str(match.get("id"))
                entry = {
                    "league": code,
                    "season": season,
                    "understat_id": match_id,
                    "datetime": match.get("datetime"),
                    "home_team": (match.get("h") or {}).get("title"),
                    "away_team": (match.get("a") or {}).get("title"),
                    "home_goals": number((match.get("goals") or {}).get("h")),
                    "away_goals": number((match.get("goals") or {}).get("a")),
                    "home_xg": number((match.get("xG") or {}).get("h")),
                    "away_xg": number((match.get("xG") or {}).get("a")),
                    "players": [],
                }

                cache_file = cache_dir / f"{match_id}.json"
                rosters = None
                if cache_file.is_file():
                    try:
                        rosters = json.loads(cache_file.read_text())
                    except ValueError:
                        cache_file.unlink()
                if rosters is None and budget > 0:
                    time.sleep(FETCH_SLEEP_SECONDS)
                    payload = get_json(session, f"getMatchData/{match_id}")
                    budget -= 1
                    if payload and isinstance(payload.get("rosters"), dict):
                        rosters = payload["rosters"]
                        cache_file.write_text(json.dumps(rosters, ensure_ascii=False))

                if rosters is not None:
                    entry["players"] = parse_roster(rosters)
                else:
                    rosters_missing += 1

                matches_out.append(entry)

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    tmp = output.with_suffix(output.suffix + ".tmp")
    tmp.write_text(json.dumps({
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "match_count": len(matches_out),
        "rosters_missing": rosters_missing,
        "matches": matches_out,
    }, ensure_ascii=False))
    tmp.replace(output)

    log.info("Wrote %d matches (%d rosters still pending) to %s",
             len(matches_out), rosters_missing, output)


if __name__ == "__main__":
    main()
