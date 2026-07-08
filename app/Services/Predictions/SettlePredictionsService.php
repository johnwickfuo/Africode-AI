<?php

namespace App\Services\Predictions;

use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\ModelAccuracy;
use App\Models\PredictionMarket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nightly settlement (spec section 6, 03:30): scores every pending
 * prediction_markets row against the finished fixture's actual stats and
 * rebuilds the model_accuracy summary table.
 *
 * Goals markets settle from the fixture result alone; corners, cards, and
 * shots-on-target need the FBref match stats, which can lag a result by a
 * night — rows whose actual value isn't known yet simply stay pending and
 * settle on a later run. Postponed fixtures void their markets.
 */
class SettlePredictionsService
{
    /**
     * @return array{settled: int, voided: int, awaiting_stats: int, accuracy_rows: int}
     */
    public function run(): array
    {
        $summary = ['settled' => 0, 'voided' => 0, 'awaiting_stats' => 0];

        $pending = PredictionMarket::query()
            ->where('outcome', PredictionMarket::OUTCOME_PENDING)
            ->whereHas('prediction.fixture', fn ($query) => $query->whereIn(
                'status',
                [Fixture::STATUS_FINISHED, Fixture::STATUS_POSTPONED],
            ))
            ->with(['prediction.fixture.matchStats'])
            ->get();

        $actualsByFixture = [];

        foreach ($pending as $market) {
            $fixture = $market->prediction->fixture;

            if ($fixture->status === Fixture::STATUS_POSTPONED) {
                $market->update(['outcome' => PredictionMarket::OUTCOME_VOID, 'settled_at' => now()]);
                $summary['voided']++;

                continue;
            }

            $actuals = $actualsByFixture[$fixture->id] ??= $this->actuals($fixture);
            $actual = $actuals[$market->market] ?? null;

            if ($actual === null) {
                $summary['awaiting_stats']++;

                continue;
            }

            $market->update([
                'outcome' => $this->outcome($market, $actual),
                'settled_at' => now(),
            ]);
            $summary['settled']++;
        }

        $summary['accuracy_rows'] = $this->rebuildModelAccuracy();

        Log::info('Prediction settlement finished', $summary);

        return $summary;
    }

    /**
     * Actual per-market values for a finished fixture; null when unknowable
     * yet (missing stats). Half lines mean pushes are impossible.
     *
     * @return array<string, int|bool|null>
     */
    private function actuals(Fixture $fixture): array
    {
        $home = $fixture->matchStats->firstWhere('team_id', $fixture->home_team_id);
        $away = $fixture->matchStats->firstWhere('team_id', $fixture->away_team_id);

        $goalsKnown = $fixture->home_goals !== null && $fixture->away_goals !== null;

        return [
            'goals' => $goalsKnown ? $fixture->home_goals + $fixture->away_goals : null,
            'team_goals_home' => $fixture->home_goals,
            'team_goals_away' => $fixture->away_goals,
            'btts' => $goalsKnown ? ($fixture->home_goals >= 1 && $fixture->away_goals >= 1) : null,
            'corners' => $this->pairTotal($home, $away, 'corners_for', 'corners_against'),
            'team_corners_home' => $home?->corners_for,
            'team_corners_away' => $away?->corners_for,
            'cards' => $this->cardsTotal($home, $away),
            'shots_on_target' => $this->pairTotal($home, $away, 'shots_on_target', 'shots_on_target_against'),
            'team_sot_home' => $home?->shots_on_target,
            'team_sot_away' => $away?->shots_on_target,
        ];
    }

    /**
     * Match total for a stat that each team row records both for and against:
     * both teams' "for" values, or a single row's for + against.
     */
    private function pairTotal(?MatchStat $home, ?MatchStat $away, string $for, string $against): ?int
    {
        if ($home?->{$for} !== null && $away?->{$for} !== null) {
            return $home->{$for} + $away->{$for};
        }

        foreach ([$home, $away] as $row) {
            if ($row?->{$for} !== null && $row->{$against} !== null) {
                return $row->{$for} + $row->{$against};
            }
        }

        return null;
    }

    private function cardsTotal(?MatchStat $home, ?MatchStat $away): ?int
    {
        // Cards have no "against" column, so both rows are required.
        if ($home === null || $away === null || $home->yellows === null || $away->yellows === null) {
            return null;
        }

        return $home->yellows + ($home->reds ?? 0) + $away->yellows + ($away->reds ?? 0);
    }

    private function outcome(PredictionMarket $market, int|bool $actual): string
    {
        $won = match ($market->direction) {
            'over' => $actual > $market->line,
            'under' => $actual < $market->line,
            'yes' => $actual === true,
            'no' => $actual === false,
        };

        return $won ? PredictionMarket::OUTCOME_WON : PredictionMarket::OUTCOME_LOST;
    }

    /**
     * Full rebuild of the model_accuracy summary from settled rows: one row
     * per (market, line bucket) with hit rate vs claimed probability.
     */
    private function rebuildModelAccuracy(): int
    {
        $rows = PredictionMarket::query()
            ->whereIn('outcome', [PredictionMarket::OUTCOME_WON, PredictionMarket::OUTCOME_LOST])
            ->get(['market', 'line', 'direction', 'probability', 'outcome'])
            ->groupBy(fn (PredictionMarket $row) => $row->market.'|'.(
                $row->line !== null ? number_format((float) $row->line, 1) : $row->direction
            ));

        DB::transaction(function () use ($rows) {
            ModelAccuracy::query()->delete();

            foreach ($rows as $key => $bucketRows) {
                [$market, $bucket] = explode('|', $key, 2);
                $hits = $bucketRows->where('outcome', PredictionMarket::OUTCOME_WON)->count();
                $total = $bucketRows->count();
                $avgProbability = $bucketRows->avg('probability');

                ModelAccuracy::create([
                    'market' => $market,
                    'line_bucket' => $bucket,
                    'total_settled' => $total,
                    'hits' => $hits,
                    'hit_rate' => round($hits / $total, 4),
                    'avg_probability' => round($avgProbability, 4),
                    'calibration_gap' => round($avgProbability - $hits / $total, 4),
                ]);
            }
        });

        return $rows->count();
    }
}
