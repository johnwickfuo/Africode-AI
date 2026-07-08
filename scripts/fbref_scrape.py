#!/usr/bin/env python3
"""FBref team-match-log scraper for Africode Football AI.

Reads the Big-5 leagues schedule and team match logs for the tracked seasons
via the soccerdata library and writes a single JSON document that Laravel's
ImportFbrefDataJob consumes. Only completed matches (with a final score) are
exported — one entry per match with a stats block per team.

soccerdata caches to ~/.soccerdata and throttles politely; caching must stay
on (FBref blocks aggressive scrapers). The first run over three seasons is
slow by design; nightly runs only fetch new/changed pages.

A failed stat table is non-fatal (partial data beats no data); only a failed
schedule read aborts the run, leaving the previous JSON in place so the app
keeps predicting from the last good data.

Usage:
    python3 fbref_scrape.py --output storage/app/pipeline/fbref_latest.json \
        --seasons 2324 2425 2526
"""

from __future__ import annotations

import argparse
import json
import logging
import math
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import pandas as pd

BIG5 = "Big 5 European Leagues Combined"
DEFAULT_SEASONS = ["2324", "2425", "2526"]
MAX_ATTEMPTS = 3
BACKOFF_SECONDS = 60

# stat_type -> {output field: FBref column}. Merged with first-write-wins, so
# passing_types is the primary source for corners/crosses and misc the backup
# (FBref has moved columns between tables before).
WANTED_STATS = {
    "shooting": {"goals": "GF", "shots": "Sh", "shots_on_target": "SoT", "xg": "xG"},
    "keeper": {"shots_on_target_against": "SoTA"},
    "passing_types": {"corners_for": "CK", "crosses": "Crs"},
    "misc": {
        "fouls_committed": "Fls",
        "fouls_drawn": "Fld",
        "yellows": "CrdY",
        "reds": "CrdR",
        "corners_for": "CK",
        "crosses": "Crs",
    },
    "possession": {"possession": "Poss"},
}

STAT_FIELDS = (
    "goals",
    "xg",
    "xga",
    "shots",
    "shots_on_target",
    "shots_on_target_against",
    "corners_for",
    "corners_against",
    "crosses",
    "fouls_committed",
    "fouls_drawn",
    "yellows",
    "reds",
    "possession",
)

log = logging.getLogger("fbref_scrape")


def read_with_retry(label, reader):
    """Run a soccerdata read with exponential-backoff retries; None on failure."""
    for attempt in range(1, MAX_ATTEMPTS + 1):
        try:
            return reader()
        except Exception:
            log.exception("%s failed (attempt %d/%d)", label, attempt, MAX_ATTEMPTS)
            if attempt < MAX_ATTEMPTS:
                time.sleep(BACKOFF_SECONDS * 2 ** (attempt - 1))
    return None


def flatten_columns(df):
    """Join MultiIndex column tuples into flat 'Group_Stat' names."""
    df = df.copy()
    if isinstance(df.columns, pd.MultiIndex):
        df.columns = [
            "_".join(str(part) for part in parts if str(part) not in ("", "nan"))
            for parts in df.columns
        ]
    else:
        df.columns = [str(column) for column in df.columns]
    return df


def find_column(df, name):
    """Find a column by its final path segment, case-insensitively."""
    target = str(name).lower()
    for column in df.columns:
        lowered = column.lower()
        if lowered == target or lowered.endswith("_" + target):
            return column
    return None


def numeric(value):
    """Coerce to int/rounded float; None for missing/unparseable values."""
    if value is None:
        return None
    try:
        if pd.isna(value):
            return None
    except (TypeError, ValueError):
        pass
    try:
        as_float = float(value)
    except (TypeError, ValueError):
        return None
    if math.isnan(as_float) or math.isinf(as_float):
        return None
    return int(as_float) if as_float.is_integer() else round(as_float, 2)


def text(value):
    if value is None:
        return None
    try:
        if pd.isna(value):
            return None
    except (TypeError, ValueError):
        pass
    cleaned = str(value).strip()
    return cleaned or None


SCORE_RE = re.compile(r"^\s*(\d+)\s*[–—-]\s*(\d+)")


def parse_score(score):
    """'2–0' (any dash) -> (2, 0); None when unplayed/postponed."""
    if score is None:
        return None
    match = SCORE_RE.match(str(score))
    if not match:
        return None
    return int(match.group(1)), int(match.group(2))


def parse_matchday(week, round_label):
    week_number = numeric(week)
    if isinstance(week_number, int) and week_number > 0:
        return week_number
    if round_label:
        found = re.search(r"(\d+)", str(round_label))
        if found:
            return int(found.group(1))
    return None


def collect_team_stats(fbref):
    """(league, season, game, team) -> stats dict, merged across stat tables."""
    stats = {}
    failed_tables = []

    for stat_type, fields in WANTED_STATS.items():
        df = read_with_retry(
            f"read_team_match_stats({stat_type})",
            lambda st=stat_type: fbref.read_team_match_stats(stat_type=st),
        )
        if df is None:
            failed_tables.append(stat_type)
            continue

        df = flatten_columns(df.reset_index())
        key_columns = {name: find_column(df, name) for name in ("league", "season", "game", "team")}
        if not all(key_columns.values()):
            log.error("%s table is missing key columns %s — skipped", stat_type, key_columns)
            failed_tables.append(stat_type)
            continue

        field_columns = {field: find_column(df, source) for field, source in fields.items()}
        missing = [fields[f] for f, c in field_columns.items() if c is None]
        if missing:
            log.warning("%s table is missing columns %s", stat_type, missing)

        for row in df.to_dict("records"):
            key = tuple(text(row.get(key_columns[name])) for name in ("league", "season", "game", "team"))
            if None in key:
                continue
            entry = stats.setdefault(key, {})
            for field, column in field_columns.items():
                if column is None:
                    continue
                value = numeric(row.get(column))
                if value is not None and entry.get(field) is None:
                    entry[field] = value

    return stats, failed_tables


def team_side_stats(own, opponent, goals, own_xg_fallback, opponent_xg_fallback):
    """Final per-team stats block, deriving *against* fields from the opponent."""
    side = {field: own.get(field) for field in STAT_FIELDS}

    if side["goals"] is None:
        side["goals"] = goals
    if side["xg"] is None:
        side["xg"] = own_xg_fallback

    side["xga"] = opponent.get("xg")
    if side["xga"] is None:
        side["xga"] = opponent_xg_fallback
    if side["shots_on_target_against"] is None:
        side["shots_on_target_against"] = opponent.get("shots_on_target")
    side["corners_against"] = opponent.get("corners_for")

    return side


def collect_matches(fbref, stats):
    schedule = read_with_retry("read_schedule()", fbref.read_schedule)
    if schedule is None:
        log.error("Schedule read failed after retries — aborting, previous JSON stays in place.")
        sys.exit(1)

    df = flatten_columns(schedule.reset_index())
    columns = {
        name: find_column(df, name)
        for name in (
            "league", "season", "game", "date", "time", "week", "round",
            "home_team", "away_team", "score", "referee", "home_xg", "away_xg",
        )
    }
    for required in ("league", "season", "game", "home_team", "away_team", "score"):
        if columns[required] is None:
            log.error("Schedule is missing required column '%s' — aborting.", required)
            sys.exit(1)

    def value(row, name):
        column = columns.get(name)
        return row.get(column) if column else None

    matches = []
    for row in df.to_dict("records"):
        goals = parse_score(value(row, "score"))
        if goals is None:
            continue  # unplayed or postponed — results only

        league = text(value(row, "league"))
        season = text(value(row, "season"))
        game = text(value(row, "game"))
        home_team = text(value(row, "home_team"))
        away_team = text(value(row, "away_team"))
        if None in (league, season, game, home_team, away_team):
            continue

        home_goals, away_goals = goals
        home_xg = numeric(value(row, "home_xg"))
        away_xg = numeric(value(row, "away_xg"))
        home_raw = stats.get((league, season, game, home_team), {})
        away_raw = stats.get((league, season, game, away_team), {})

        date = text(value(row, "date"))
        kickoff_time = text(value(row, "time"))

        matches.append({
            "league": league,
            "season": season,
            "game": game,
            "date": date,
            # FBref lists venue-local kickoff times; the importer treats this
            # as approximate. Date-level precision is enough for modeling.
            "kickoff": f"{date} {kickoff_time}" if date and kickoff_time else date,
            "matchday": parse_matchday(value(row, "week"), text(value(row, "round"))),
            "referee": text(value(row, "referee")),
            "home_team": home_team,
            "away_team": away_team,
            "home_goals": home_goals,
            "away_goals": away_goals,
            "home": team_side_stats(home_raw, away_raw, home_goals, home_xg, away_xg),
            "away": team_side_stats(away_raw, home_raw, away_goals, away_xg, home_xg),
        })

    return matches


def main():
    parser = argparse.ArgumentParser(description="Scrape FBref Big-5 team match logs to JSON.")
    parser.add_argument("--output", required=True, help="Path of the JSON file to write")
    parser.add_argument("--seasons", nargs="+", default=DEFAULT_SEASONS,
                        help="Season keys, e.g. 2324 2425 2526")
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, stream=sys.stderr,
                        format="%(asctime)s %(levelname)s %(name)s: %(message)s")

    try:
        import soccerdata as sd
    except ImportError:
        log.error("soccerdata is not installed. Run: pip install -r scripts/requirements.txt")
        sys.exit(1)

    fbref = sd.FBref(leagues=BIG5, seasons=args.seasons)

    stats, failed_tables = collect_team_stats(fbref)
    matches = collect_matches(fbref, stats)

    payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "seasons": args.seasons,
        "failed_stat_tables": failed_tables,
        "match_count": len(matches),
        "matches": matches,
    }

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    tmp = output.with_suffix(output.suffix + ".tmp")
    tmp.write_text(json.dumps(payload, ensure_ascii=False))
    tmp.replace(output)  # atomic: importer never sees a half-written file

    log.info("Wrote %d matches (%d team-stat rows, failed tables: %s) to %s",
             len(matches), len(stats), failed_tables or "none", output)


if __name__ == "__main__":
    main()
