#!/usr/bin/env python3
"""Prediction models for Africode Football AI (spec section 7).

Reads a fixtures-and-profiles JSON exported by Laravel's
GeneratePredictionsJob, evaluates every market line, and writes a
predictions JSON that Laravel imports into predictions/prediction_markets.

Models:
  7.1 Goals            — Dixon-Coles adjusted Poisson on attack/defence
                         strengths (profiles are pre-blended 70% xG / 30%
                         goals with exponential recency weighting).
  7.2 Corners          — Negative binomial; dispersion fitted from league
                         history by method of moments; crossing-volume
                         style uplift.
  7.3 Cards            — Referee-centred negative binomial with a derby
                         uplift; league-average referee fallback flagged
                         via referee_known=false.
  7.4 Shots on target  — Poisson per team from SoT for/against averages.
  7.5 Best Bet         — highest confidence margin |p - 0.5| inside the
                         configured probability window; cards picks are
                         penalized x0.8 when the referee is unknown.

Distributions use exact log-gamma arithmetic from the stdlib, so this
script is dependency-free and fully deterministic (scipy in
requirements.txt is for future model fitting work).

Usage:
    python3 predict.py --input predict_input.json --output predictions_latest.json
"""

from __future__ import annotations

import argparse
import json
import logging
import math
import sys
from datetime import datetime, timezone
from pathlib import Path

MODEL_VERSION = "v1.1.0"

# Dixon-Coles low-score correlation. A full MLE refit is a later model
# version; -0.10 is in the range fitted across European leagues.
RHO = -0.10
MAX_GOALS = 10
DEFAULT_HOME_ADVANTAGE = 1.10
STRENGTH_CLAMP = (0.3, 3.0)
HOME_ADV_CLAMP = (0.9, 1.5)

GOAL_LINES = (0.5, 1.5, 2.5, 3.5, 4.5)
TEAM_GOAL_LINES = (0.5, 1.5, 2.5)
CORNER_LINES = (7.5, 8.5, 9.5, 10.5, 11.5)
TEAM_CORNER_LINES = (3.5, 4.5, 5.5)
CARD_LINES = (2.5, 3.5, 4.5, 5.5)
SOT_TOTAL_LINES = (6.5, 7.5, 8.5)
TEAM_SOT_LINES = (2.5, 3.5, 4.5, 5.5)

# Cards model (7.3): referee is the strongest predictor when known.
CARDS_REFEREE_WEIGHT = 0.55
CARDS_TEAMS_WEIGHT = 0.45
MIN_REFEREE_MATCHES = 5
DERBY_CARDS_UPLIFT = 0.4
FOUL_FACTOR_CLAMP = (0.9, 1.1)

# Corners style uplift (7.2): crossing volume vs league norm.
STYLE_UPLIFT_SLOPE = 0.10
STYLE_UPLIFT_CLAMP = (0.95, 1.10)

# Modest venue split for shots on target (profiles are venue-agnostic).
SOT_HOME_FACTOR = 1.05
SOT_AWAY_FACTOR = 0.95

CARDS_UNKNOWN_REF_PENALTY = 0.8

PMF_SUPPORT_CAP = 60  # tail cutoff for count distributions

log = logging.getLogger("predict")


def clamp(value, bounds):
    low, high = bounds
    return max(low, min(high, value))


# --- distributions ---------------------------------------------------------


def poisson_pmf(mu, x):
    if mu <= 0:
        return 1.0 if x == 0 else 0.0
    return math.exp(-mu + x * math.log(mu) - math.lgamma(x + 1))


def nb_pmf(mu, k, x):
    """NB2: mean mu, variance mu + mu^2/k. Falls back to Poisson when the
    dispersion is missing (league variance did not exceed the mean)."""
    if mu <= 0:
        return 1.0 if x == 0 else 0.0
    if k is None or k <= 0:
        return poisson_pmf(mu, x)
    return math.exp(
        math.lgamma(x + k) - math.lgamma(k) - math.lgamma(x + 1)
        + k * math.log(k / (k + mu)) + x * math.log(mu / (k + mu))
    )


def prob_over(line, pmf):
    """P(X > line) for a half line, from an exact pmf."""
    p_at_most = sum(pmf(x) for x in range(0, math.floor(line) + 1))
    return clamp(1.0 - p_at_most, (0.0, 1.0))


def dispersion_from_moments(mean, variance):
    """Method-of-moments NB dispersion k = mu^2 / (var - mu); None => Poisson."""
    if mean is None or variance is None or mean <= 0 or variance <= mean:
        return None
    return mean * mean / (variance - mean)


# --- goals (Dixon-Coles) ---------------------------------------------------


def dixon_coles_matrix(lam_home, lam_away, rho):
    def tau(x, y):
        if x == 0 and y == 0:
            return 1 - lam_home * lam_away * rho
        if x == 0 and y == 1:
            return 1 + lam_home * rho
        if x == 1 and y == 0:
            return 1 + lam_away * rho
        if x == 1 and y == 1:
            return 1 - rho
        return 1.0

    matrix = [
        [poisson_pmf(lam_home, x) * poisson_pmf(lam_away, y) * tau(x, y)
         for y in range(MAX_GOALS + 1)]
        for x in range(MAX_GOALS + 1)
    ]
    total = sum(sum(row) for row in matrix)
    return [[p / total for p in row] for row in matrix]


def goals_markets(fixture, league):
    home, away = fixture["home"], fixture["away"]
    league_avg = league.get("goals_blend_avg")
    inputs = (
        home.get("attack_strength"), home.get("defence_strength"),
        away.get("attack_strength"), away.get("defence_strength"),
    )
    if league_avg is None or league_avg <= 0 or None in inputs:
        return None

    attack_home, defence_home, attack_away, defence_away = (
        clamp(value, STRENGTH_CLAMP) for value in inputs
    )
    home_adv = clamp(home.get("home_advantage_factor") or DEFAULT_HOME_ADVANTAGE, HOME_ADV_CLAMP)

    lam_home = attack_home * defence_away * home_adv * league_avg
    lam_away = attack_away * defence_home * league_avg
    matrix = dixon_coles_matrix(lam_home, lam_away, RHO)

    total_over = {}
    home_over = {}
    away_over = {}
    btts_yes = 0.0
    p_home = p_draw = p_away = 0.0
    for x in range(MAX_GOALS + 1):
        for y in range(MAX_GOALS + 1):
            p = matrix[x][y]
            if x > y:
                p_home += p
            elif x == y:
                p_draw += p
            else:
                p_away += p
            for line in GOAL_LINES:
                if x + y > line:
                    total_over[line] = total_over.get(line, 0.0) + p
            for line in TEAM_GOAL_LINES:
                if x > line:
                    home_over[line] = home_over.get(line, 0.0) + p
                if y > line:
                    away_over[line] = away_over.get(line, 0.0) + p
            if x >= 1 and y >= 1:
                btts_yes += p

    rows = [result_row(p_home, p_draw, p_away)]
    rows += [market_row("goals", line, total_over.get(line, 0.0)) for line in GOAL_LINES]
    rows.append(btts_row(btts_yes))
    rows += [market_row("team_goals_home", line, home_over.get(line, 0.0)) for line in TEAM_GOAL_LINES]
    rows += [market_row("team_goals_away", line, away_over.get(line, 0.0)) for line in TEAM_GOAL_LINES]
    return rows


# --- corners (negative binomial) -------------------------------------------


def corners_markets(fixture, league):
    home, away = fixture["home"], fixture["away"]
    inputs = (
        home.get("corners_for_avg"), away.get("corners_against_avg"),
        away.get("corners_for_avg"), home.get("corners_against_avg"),
    )
    if None in inputs:
        return None

    mu_home = (inputs[0] + inputs[1]) / 2
    mu_away = (inputs[2] + inputs[3]) / 2

    # Style uplift: heavy crossing sides vs the league norm win more corners.
    league_crosses = league.get("crosses_avg")
    home_crosses, away_crosses = home.get("crosses_avg"), away.get("crosses_avg")
    if league_crosses and home_crosses is not None and away_crosses is not None:
        crossing_ratio = (home_crosses + away_crosses) / (2 * league_crosses)
        style = clamp(1 + STYLE_UPLIFT_SLOPE * (crossing_ratio - 1), STYLE_UPLIFT_CLAMP)
        mu_home *= style
        mu_away *= style

    mu_total = mu_home + mu_away
    if mu_total <= 0:
        return None

    k_total = dispersion_from_moments(league.get("corners_total_mean"), league.get("corners_total_var"))
    # NB2 with a shared success probability is additive in k, so team-level
    # dispersion splits proportionally to the mean.
    k_home = k_total * mu_home / mu_total if k_total else None
    k_away = k_total * mu_away / mu_total if k_total else None

    rows = [
        market_row("corners", line, prob_over(line, lambda x: nb_pmf(mu_total, k_total, x)))
        for line in CORNER_LINES
    ]
    rows += [
        market_row("team_corners_home", line, prob_over(line, lambda x: nb_pmf(mu_home, k_home, x)))
        for line in TEAM_CORNER_LINES
    ]
    rows += [
        market_row("team_corners_away", line, prob_over(line, lambda x: nb_pmf(mu_away, k_away, x)))
        for line in TEAM_CORNER_LINES
    ]
    return rows


# --- cards (referee-centred negative binomial) ------------------------------


def cards_markets(fixture, league):
    home, away = fixture["home"], fixture["away"]
    referee = fixture.get("referee") or {}

    referee_known = (
        (referee.get("matches_officiated") or 0) >= MIN_REFEREE_MATCHES
        and referee.get("avg_yellows_per_match") is not None
    )
    if referee_known:
        referee_cards = referee["avg_yellows_per_match"] + (referee.get("avg_reds_per_match") or 0)
    else:
        referee_cards = league.get("cards_total_mean")

    team_cards = (home.get("cards_avg"), away.get("cards_avg"))
    if referee_cards is None or None in team_cards:
        return None, referee_known

    foul_factor = 1.0
    league_fouls = league.get("fouls_avg")
    home_fouls, away_fouls = home.get("fouls_committed_avg"), away.get("fouls_committed_avg")
    if league_fouls and home_fouls is not None and away_fouls is not None:
        foul_factor = clamp(
            math.sqrt((home_fouls + away_fouls) / (2 * league_fouls)),
            FOUL_FACTOR_CLAMP,
        )

    mu = (
        CARDS_REFEREE_WEIGHT * referee_cards
        + CARDS_TEAMS_WEIGHT * (team_cards[0] + team_cards[1]) * foul_factor
    )
    if fixture.get("is_derby"):
        mu += DERBY_CARDS_UPLIFT

    k = dispersion_from_moments(league.get("cards_total_mean"), league.get("cards_total_var"))
    rows = [
        market_row("cards", line, prob_over(line, lambda x: nb_pmf(mu, k, x)))
        for line in CARD_LINES
    ]
    return rows, referee_known


# --- shots on target (Poisson) ----------------------------------------------


def sot_markets(fixture):
    home, away = fixture["home"], fixture["away"]
    inputs = (
        home.get("sot_for_avg"), away.get("sot_against_avg"),
        away.get("sot_for_avg"), home.get("sot_against_avg"),
    )
    if None in inputs:
        return None

    mu_home = (inputs[0] + inputs[1]) / 2 * SOT_HOME_FACTOR
    mu_away = (inputs[2] + inputs[3]) / 2 * SOT_AWAY_FACTOR
    mu_total = mu_home + mu_away  # sum of independent Poissons is Poisson

    rows = [
        market_row("shots_on_target", line, prob_over(line, lambda x: poisson_pmf(mu_total, x)))
        for line in SOT_TOTAL_LINES
    ]
    rows += [
        market_row("team_sot_home", line, prob_over(line, lambda x: poisson_pmf(mu_home, x)))
        for line in TEAM_SOT_LINES
    ]
    rows += [
        market_row("team_sot_away", line, prob_over(line, lambda x: poisson_pmf(mu_away, x)))
        for line in TEAM_SOT_LINES
    ]
    return rows


# --- market rows & best bet -------------------------------------------------


def market_row(market, line, p_over):
    """One row per line, on the side the model favours (p >= 0.5)."""
    direction, probability = ("over", p_over) if p_over >= 0.5 else ("under", 1 - p_over)
    return {
        "market": market,
        "line": line,
        "direction": direction,
        "probability": round(probability, 4),
        "confidence_margin": round(abs(probability - 0.5), 4),
    }


def result_row(p_home, p_draw, p_away):
    """1X2: the favoured outcome from the Dixon-Coles matrix. One row (the
    pick), like every other market — the losing outcomes are implied."""
    direction, probability = max(
        (("home", p_home), ("draw", p_draw), ("away", p_away)),
        key=lambda pair: pair[1],
    )
    return {
        "market": "result",
        "line": None,
        "direction": direction,
        "probability": round(probability, 4),
        # Same scale as the binary markets so Best Bet ranking stays fair:
        # a sub-50% favourite never outranks a confident line pick.
        "confidence_margin": round(max(probability - 0.5, 0.0), 4),
    }


def btts_row(p_yes):
    direction, probability = ("yes", p_yes) if p_yes >= 0.5 else ("no", 1 - p_yes)
    return {
        "market": "btts",
        "line": None,
        "direction": direction,
        "probability": round(probability, 4),
        "confidence_margin": round(abs(probability - 0.5), 4),
    }


def selection_score(row, referee_known):
    score = row["confidence_margin"]
    if row["market"] == "cards" and not referee_known:
        score *= CARDS_UNKNOWN_REF_PENALTY
    return score


def is_bettable(row, config):
    """A headline pick must be one a mainstream bookmaker actually prices.

    Shots-on-target markets are modelled and shown, but few African books
    offer them, and a Best Bet nobody can place is worse than no Best Bet.
    Very low lines ("over 0.5 goals") are dropped for the same reason: they
    are available but pay so little that headlining one looks like filler.
    """
    bettable = config.get("bettable_markets")
    if bettable is not None and row["market"] not in bettable:
        return False

    min_line = config.get("min_headline_line")
    if min_line is not None and row["line"] is not None and row["line"] < min_line:
        return False

    return True


def select_best_bet(rows, referee_known, config):
    min_prob = config.get("best_bet_min_prob", 0.62)
    max_prob = config.get("best_bet_max_prob", 0.92)

    placeable = [r for r in rows if is_bettable(r, config)] or rows

    candidates = [r for r in placeable if min_prob <= r["probability"] <= max_prob]
    if not candidates:
        # Nothing in the window (rare): relax the floor, keep the triviality
        # ceiling so "over 0.5 corners" style picks never headline.
        candidates = [r for r in placeable if r["probability"] <= max_prob] or placeable

    return max(
        candidates,
        key=lambda r: (
            selection_score(r, referee_known),
            -r["probability"],  # deterministic tie-breaks
            r["market"],
            r["line"] if r["line"] is not None else -1,
        ),
    )


def headline(fixture, row):
    percent = f"{round(row['probability'] * 100)}%"
    market, line, direction = row["market"], row["line"], row["direction"]

    if market == "btts":
        return f"Both teams to score: {'Yes' if direction == 'yes' else 'No'} — {percent}"

    if market == "result":
        if direction == "draw":
            return f"Draw — {percent}"
        side = fixture["home"]["name"] if direction == "home" else fixture["away"]["name"]
        return f"{side} to win — {percent}"

    word = "Over" if direction == "over" else "Under"
    plain = {"goals": "goals", "corners": "corners", "cards": "cards",
             "shots_on_target": "shots on target"}
    if market in plain:
        return f"{word} {line} {plain[market]} — {percent}"

    team = fixture["home"]["name"] if market.endswith("_home") else fixture["away"]["name"]
    unit = "goals" if "goals" in market else "corners" if "corners" in market else "shots on target"
    return f"{team} {word.lower()} {line} {unit} — {percent}"


# --- ML challenger (1X2) -----------------------------------------------------
#
# A softmax (multinomial logistic) regression over the profile features that
# Laravel exports as training rows, predicting home/draw/away head-to-head
# against the Dixon-Coles champion. Its predictions are stored separately
# (is_challenger) and never shown as picks — the accuracy tracker referees.
# numpy comes with the pipeline venv (soccerdata dependency); if it's absent
# the challenger is skipped and the champion pipeline is unaffected.

CHALLENGER_VERSION = "ml-1x2-v1.1.0"
CHALLENGER_MIN_TRAINING = 300
CHALLENGER_CLASSES = ["home", "draw", "away"]


def train_challenger(training):
    try:
        import numpy as np
    except ImportError:
        log.warning("numpy unavailable — challenger model skipped")
        return None

    rows = [r for r in training if r.get("features") and None not in r["features"]]
    if len(rows) < CHALLENGER_MIN_TRAINING:
        log.info("Challenger: %d training rows (< %d) — skipped", len(rows), CHALLENGER_MIN_TRAINING)
        return None

    x = np.array([r["features"] for r in rows], dtype=float)
    y = np.array([CHALLENGER_CLASSES.index(r["result"]) for r in rows])

    mean, std = x.mean(axis=0), x.std(axis=0)
    std[std == 0] = 1.0
    x = np.hstack([(x - mean) / std, np.ones((len(x), 1))])  # standardize + bias

    rng = np.random.default_rng(7)  # deterministic nightly retrains
    weights = rng.normal(0, 0.01, size=(x.shape[1], len(CHALLENGER_CLASSES)))
    onehot = np.eye(len(CHALLENGER_CLASSES))[y]
    lr, l2 = 0.5, 1e-3

    for _ in range(400):
        logits = x @ weights
        logits -= logits.max(axis=1, keepdims=True)
        probs = np.exp(logits)
        probs /= probs.sum(axis=1, keepdims=True)
        grad = x.T @ (probs - onehot) / len(x) + l2 * weights
        weights -= lr * grad

    return {"weights": weights, "mean": mean, "std": std, "np": np, "samples": len(rows)}


def challenger_predict(model, features):
    np = model["np"]
    x = (np.array(features, dtype=float) - model["mean"]) / model["std"]
    logits = np.append(x, 1.0) @ model["weights"]
    logits -= logits.max()
    probs = np.exp(logits)
    probs /= probs.sum()

    pick = int(probs.argmax())
    return {
        "market": "result",
        "line": None,
        "direction": CHALLENGER_CLASSES[pick],
        "probability": round(float(probs[pick]), 4),
        "confidence_margin": round(max(float(probs[pick]) - 0.5, 0.0), 4),
    }


def challenger_features(fixture):
    """Must mirror the order Laravel uses for training rows: profile
    features, then pre-match form (ppg last 5, rest days), then derby."""
    home, away = fixture["home"], fixture["away"]
    features = []
    for key in ("attack_strength", "defence_strength", "xg_for_avg",
                "xg_against_avg", "sot_for_avg", "sot_against_avg"):
        features.append(home.get(key))
        features.append(away.get(key))
    features.append(home.get("home_advantage_factor"))

    form = fixture.get("form") or {}
    features.append(form.get("home_ppg5", 1.3))
    features.append(form.get("away_ppg5", 1.3))
    features.append(form.get("home_rest", 7.0))
    features.append(form.get("away_rest", 7.0))
    features.append(1.0 if fixture.get("is_derby") else 0.0)
    return features


def run_challenger(payload):
    model = train_challenger(payload.get("training") or [])
    if model is None:
        return []

    predictions = []
    for fixture in payload.get("fixtures", []):
        features = challenger_features(fixture)
        if None in features:
            continue
        row = challenger_predict(model, features)
        predictions.append({
            "fixture_id": fixture["fixture_id"],
            "best_bet": {**row, "headline": headline(fixture, row)},
            "markets": [row],
        })

    log.info("Challenger: trained on %d results, predicted %d fixtures",
             model["samples"], len(predictions))
    return predictions


# --- driver ------------------------------------------------------------------


def evaluate_fixture(fixture, league, config):
    rows = []

    goals = goals_markets(fixture, league)
    if goals:
        rows += goals

    corners = corners_markets(fixture, league)
    if corners:
        rows += corners

    # Cards need a named referee to be worth anything, and only a handful
    # of divisions publish one. Elsewhere the market is not offered at all.
    cards_leagues = config.get("cards_leagues")
    referee_known = False
    if cards_leagues is None or fixture.get("league_code") in cards_leagues:
        cards, referee_known = cards_markets(fixture, league)
        if cards:
            rows += cards

    sot = sot_markets(fixture)
    if sot:
        rows += sot

    if not rows:
        return None

    best = select_best_bet(rows, referee_known, config)
    return {
        "fixture_id": fixture["fixture_id"],
        "referee_known": referee_known,
        "best_bet": {**best, "headline": headline(fixture, best)},
        "markets": rows,
    }


def main():
    parser = argparse.ArgumentParser(description="Generate market predictions from profile data.")
    parser.add_argument("--input", required=True, help="Input JSON exported by Laravel")
    parser.add_argument("--output", required=True, help="Predictions JSON to write")
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, stream=sys.stderr,
                        format="%(asctime)s %(levelname)s %(name)s: %(message)s")

    payload = json.loads(Path(args.input).read_text())
    config = payload.get("config", {})
    # PHP hands us a map keyed by league id, but an EMPTY map encodes as a
    # JSON list, which used to take the whole run down on a database with
    # no finished fixtures yet.
    league_averages = payload.get("league_averages") or {}
    if not isinstance(league_averages, dict):
        league_averages = {}

    predictions = []
    skipped = []
    for fixture in payload.get("fixtures", []):
        league = league_averages.get(str(fixture.get("league_id"))) or {}
        result = evaluate_fixture(fixture, league, config)
        if result is None:
            skipped.append({
                "fixture_id": fixture.get("fixture_id"),
                "reason": "insufficient profile or league data for every market",
            })
        else:
            predictions.append(result)

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    tmp = output.with_suffix(output.suffix + ".tmp")
    challenger = run_challenger(payload)

    tmp.write_text(json.dumps({
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "model_version": MODEL_VERSION,
        "prediction_count": len(predictions),
        "predictions": predictions,
        "challenger_model_version": CHALLENGER_VERSION,
        "challenger_predictions": challenger,
        "skipped": skipped,
    }, ensure_ascii=False))
    tmp.replace(output)

    log.info("Wrote %d predictions (%d skipped) to %s", len(predictions), len(skipped), output)


if __name__ == "__main__":
    main()
