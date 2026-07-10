#!/usr/bin/env python3
"""FBref player match-stats scraper for Africode Football AI.

Given a batch of FBref match ids (from fixtures.fbref_game_id), reads each
match report's player "summary" table via soccerdata and writes one JSON
document for Laravel's player-stats import. Called nightly by
ScrapePlayerStatsJob with a bounded batch, which is what makes the 3-season
historical backfill resumable — progress lives in the player_scrape_progress
table on the Laravel side, this script is stateless.

Each match id is fetched independently: one failed match report never sinks
the batch (it is listed in failed_game_ids and retried on a later night).
soccerdata's cache + polite throttling stay on.

Usage:
    python3 fbref_scrape_players.py --output players.json \
        --seasons 2324 2425 2526 --match-ids abcd1234 ef567890
"""

from __future__ import annotations

import argparse
import json
import logging
import sys
from datetime import datetime, timezone
from pathlib import Path

from fbref_scrape import BIG5, DEFAULT_SEASONS, find_column, flatten_columns, make_fbref, numeric, read_with_retry, text

log = logging.getLogger("fbref_scrape_players")

# output field -> FBref column (matched by final path segment).
PLAYER_FIELDS = {
    "player": "player",
    "team": "team",
    "nationality": "nation",
    "position": "pos",
    "minutes": "min",
    "goals": "Gls",
    "assists": "Ast",
    "shots": "Sh",
    "shots_on_target": "SoT",
    "yellows": "CrdY",
    "reds": "CrdR",
    "xg": "xG",
    "xa": "xAG",
}

TEXT_FIELDS = ("player", "team", "nationality", "position")


def parse_players(df):
    """Player rows from one match report's summary table."""
    df = flatten_columns(df.reset_index())
    columns = {field: find_column(df, source) for field, source in PLAYER_FIELDS.items()}

    if columns["player"] is None or columns["team"] is None:
        log.error("Player table is missing player/team columns — layout change?")
        return None

    players = []
    for row in df.to_dict("records"):
        entry = {}
        for field, column in columns.items():
            raw = row.get(column) if column else None
            entry[field] = text(raw) if field in TEXT_FIELDS else numeric(raw)

        if entry["player"] is None or entry["team"] is None:
            continue

        # FBref nation strings look like "eng ENG" — keep the code.
        if entry["nationality"]:
            entry["nationality"] = entry["nationality"].split()[-1].upper()[:8]

        players.append(entry)

    return players


def main():
    parser = argparse.ArgumentParser(description="Scrape FBref player match stats for a batch of matches.")
    parser.add_argument("--output", required=True, help="Path of the JSON file to write")
    parser.add_argument("--seasons", nargs="+", default=DEFAULT_SEASONS)
    parser.add_argument("--match-ids", nargs="+", required=True, help="FBref game ids to scrape")
    parser.add_argument("--headless", action="store_true",
                        help="Run the browser headless (Cloudflare usually blocks this; headed + Xvfb is the default)")
    parser.add_argument("--proxy", default=None,
                        help='Proxy for FBref requests, e.g. "tor" (local Tor daemon on port 9050)')
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, stream=sys.stderr,
                        format="%(asctime)s %(levelname)s %(name)s: %(message)s")

    try:
        import soccerdata as sd
    except ImportError:
        log.error("soccerdata is not installed. Run: pip install -r scripts/requirements.txt")
        sys.exit(1)

    fbref = make_fbref(sd, args.seasons, headless=args.headless, proxy=args.proxy)

    matches = []
    failed = []
    for match_id in args.match_ids:
        df = read_with_retry(
            f"read_player_match_stats({match_id})",
            lambda mid=match_id: fbref.read_player_match_stats(stat_type="summary", match_id=mid),
            attempts=2,
            backoff=20,
        )
        players = parse_players(df) if df is not None and len(df) else None

        if not players:
            failed.append(match_id)
            continue

        matches.append({"game_id": match_id, "players": players})

    payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "match_count": len(matches),
        "matches": matches,
        "failed_game_ids": failed,
    }

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    tmp = output.with_suffix(output.suffix + ".tmp")
    tmp.write_text(json.dumps(payload, ensure_ascii=False))
    tmp.replace(output)

    log.info("Wrote player stats for %d matches (%d failed) to %s", len(matches), len(failed), output)


if __name__ == "__main__":
    main()
