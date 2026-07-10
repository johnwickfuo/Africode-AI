<?php

namespace App\Support;

/**
 * Season bookkeeping for European leagues, which roll over in August.
 * Computed at call time (never bake this into cached config, or the
 * tracked list would freeze at deploy time).
 */
class Seasons
{
    /**
     * The soccerdata season keys currently tracked, oldest first —
     * the current season and the two before it (e.g. in 2026-27:
     * ["2425", "2526", "2627"]).
     *
     * @return list<string>
     */
    public static function tracked(int $count = 3): array
    {
        $now = now('UTC');
        $currentStartYear = $now->month >= 8 ? $now->year : $now->year - 1;

        $keys = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $startYear = $currentStartYear - $i;
            $keys[] = substr((string) $startYear, -2).substr((string) ($startYear + 1), -2);
        }

        return $keys;
    }
}
