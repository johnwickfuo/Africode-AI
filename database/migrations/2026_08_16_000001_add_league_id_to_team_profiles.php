<?php

use App\Models\TeamProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attack and defence strengths are ratios against the league average,
     * so a row only means anything once you know which league it was
     * measured in. Without that, a promoted club's last profile — earned in
     * a division below — reads as though it belonged to the one above:
     * a Championship winner arrives in the Premier League still rated 35%
     * above average, which is roughly how a title contender is rated.
     *
     * Backfilled from the fixtures each profile was built from; the nightly
     * recompute fills it going forward.
     */
    public function up(): void
    {
        Schema::table('team_profiles', function (Blueprint $table) {
            $table->foreignId('league_id')->nullable()->after('team_id')->constrained()->nullOnDelete();
        });

        // The league a club played a season in: the one carrying most of its
        // finished fixtures that season, home and away counted together. A
        // cup run or a mid-season transfer cannot outvote a league campaign.
        $played = collect();

        foreach (['home_team_id', 'away_team_id'] as $side) {
            $played = $played->concat(
                DB::table('fixtures')
                    ->where('status', 'finished')
                    ->selectRaw("season, {$side} as team_id, league_id, count(*) as played")
                    ->groupBy('season', $side, 'league_id')
                    ->get()
            );
        }

        $counts = [];
        foreach ($played as $row) {
            $key = $row->team_id.'|'.$row->season.'|'.$row->league_id;
            $counts[$key] = ($counts[$key] ?? 0) + $row->played;
        }
        arsort($counts);

        $seen = [];
        foreach (array_keys($counts) as $key) {
            [$teamId, $season, $leagueId] = explode('|', $key);
            if (isset($seen[$teamId.'|'.$season])) {
                continue;
            }
            $seen[$teamId.'|'.$season] = true;

            TeamProfile::where('team_id', $teamId)
                ->where('season', $season)
                ->update(['league_id' => $leagueId]);
        }
    }

    public function down(): void
    {
        Schema::table('team_profiles', function (Blueprint $table) {
            $table->dropForeign(['league_id']);
            $table->dropColumn('league_id');
        });
    }
};
