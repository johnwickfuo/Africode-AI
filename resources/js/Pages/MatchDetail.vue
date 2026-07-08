<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import ProbabilityBar from '../Components/ProbabilityBar.vue';
import Sparkline from '../Components/Sparkline.vue';
import { MARKET_SECTIONS, lineLabel } from '../lib/markets';

const props = defineProps({
    fixture: { type: Object, required: true },
    referee: { type: Object, default: null },
    prediction: { type: Object, default: null },
    profiles: { type: Object, required: true },
    xg_trend: { type: Object, required: true },
    head_to_head: { type: Array, default: () => [] },
});

const sections = computed(() => {
    if (!props.prediction) return [];
    return MARKET_SECTIONS.map((section) => ({
        title: section.title,
        rows: props.prediction.markets
            .filter((row) => section.markets.includes(row.market))
            .sort(
                (a, b) =>
                    section.markets.indexOf(a.market) - section.markets.indexOf(b.market)
                    || (a.line ?? 0) - (b.line ?? 0),
            ),
    })).filter((section) => section.rows.length);
});

const profileRows = [
    ['Matches (season)', 'matches_played', 0],
    ['Attack strength', 'attack_strength', 2],
    ['Defence strength', 'defence_strength', 2],
    ['xG for / match', 'xg_for_avg', 2],
    ['xG against / match', 'xg_against_avg', 2],
    ['Corners for', 'corners_for_avg', 1],
    ['Corners against', 'corners_against_avg', 1],
    ['Crosses', 'crosses_avg', 1],
    ['Cards', 'cards_avg', 1],
    ['Fouls committed', 'fouls_committed_avg', 1],
    ['SoT for', 'sot_for_avg', 1],
    ['SoT against', 'sot_against_avg', 1],
];

const fmt = (value, decimals) =>
    value === null || value === undefined ? '—' : Number(value).toFixed(decimals);

const pageTitle = computed(
    () => `${props.fixture.home_team.short_name} v ${props.fixture.away_team.short_name}`,
);
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout>
        <!-- Match header -->
        <div class="mb-6 rounded-xl border border-slate-800 bg-slate-900 p-4">
            <div class="mb-3 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                <span class="rounded bg-slate-800 px-2 py-0.5 font-bold text-green-400">
                    {{ fixture.league.code }}
                </span>
                <span>{{ fixture.league.name }}</span>
                <span v-if="fixture.matchday">· Matchday {{ fixture.matchday }}</span>
                <span
                    v-if="fixture.is_derby"
                    class="rounded bg-amber-500/15 px-2 py-0.5 font-bold text-amber-400"
                >
                    DERBY
                </span>
            </div>
            <div class="flex items-center justify-between gap-3 text-lg font-bold sm:text-xl">
                <span class="flex-1 truncate text-right">{{ fixture.home_team.name }}</span>
                <span
                    v-if="fixture.status === 'finished'"
                    class="shrink-0 font-mono text-2xl text-green-400"
                >
                    {{ fixture.home_goals }}–{{ fixture.away_goals }}
                </span>
                <span v-else class="shrink-0 text-xs font-bold text-slate-500">vs</span>
                <span class="flex-1 truncate">{{ fixture.away_team.name }}</span>
            </div>
            <p class="mt-2 text-center text-sm text-slate-400">
                {{ fixture.kickoff_date }} · {{ fixture.kickoff_time }}
            </p>
        </div>

        <template v-if="prediction">
            <!-- Best Bet hero -->
            <div class="mb-6 rounded-xl border border-green-500/40 bg-green-500/5 p-5">
                <p class="mb-1 text-xs font-bold uppercase tracking-widest text-green-400">
                    ★ Best Bet
                </p>
                <p class="text-xl font-bold sm:text-2xl">{{ prediction.best_bet.headline }}</p>
                <div class="mt-3">
                    <ProbabilityBar :probability="prediction.best_bet.probability" />
                </div>
                <p class="mt-2 text-xs text-slate-400">
                    Model {{ prediction.model_version }} · generated {{ prediction.generated_at }}
                </p>
            </div>

            <!-- Markets -->
            <section v-for="section in sections" :key="section.title" class="mb-6">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-widest text-slate-400">
                    {{ section.title }}
                </h2>
                <div class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900">
                    <div
                        v-for="row in section.rows"
                        :key="`${row.market}-${row.line}-${row.direction}`"
                        class="flex items-center gap-3 border-b border-slate-800/60 px-4 py-2.5 last:border-b-0"
                    >
                        <span class="w-32 shrink-0 truncate text-sm font-semibold text-slate-200 sm:w-44">
                            {{ (row.market === 'btts' ? 'BTTS: ' : '')
                                + lineLabel(row, fixture.home_team.short_name, fixture.away_team.short_name) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <ProbabilityBar :probability="row.probability" />
                        </div>
                    </div>
                </div>
            </section>
        </template>

        <div
            v-else
            class="mb-6 rounded-xl border border-dashed border-slate-700 p-8 text-center text-slate-400"
        >
            <p class="font-semibold">No prediction for this fixture yet.</p>
            <p class="mt-2 text-sm">Predictions are generated daily at 06:00 for fixtures within 7 days.</p>
        </div>

        <!-- Model inputs -->
        <section class="mb-6">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-widest text-slate-400">
                Model inputs
            </h2>
            <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900">
                <table class="w-full min-w-[22rem] text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-4 py-2.5 text-left font-semibold">Rolling stat</th>
                            <th class="px-3 py-2.5 text-right font-semibold">{{ fixture.home_team.short_name }}</th>
                            <th class="px-4 py-2.5 text-right font-semibold">{{ fixture.away_team.short_name }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="[label, key, decimals] in profileRows"
                            :key="key"
                            class="border-b border-slate-800/60 last:border-b-0"
                        >
                            <td class="px-4 py-2 text-slate-400">{{ label }}</td>
                            <td class="px-3 py-2 text-right font-mono text-slate-200">
                                {{ fmt(profiles.home?.[key], decimals) }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-slate-200">
                                {{ fmt(profiles.away?.[key], decimals) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        xG trend (last {{ xg_trend.home.length }})
                    </p>
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-300">{{ fixture.home_team.short_name }}</span>
                            <Sparkline :values="xg_trend.home" :label="fixture.home_team.name" />
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-slate-300">{{ fixture.away_team.short_name }}</span>
                            <Sparkline :values="xg_trend.away" :label="fixture.away_team.name" />
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Referee
                    </p>
                    <template v-if="referee">
                        <p class="font-semibold">{{ referee.name }}</p>
                        <p class="mt-1 text-sm text-slate-400">
                            {{ referee.matches_officiated }} matches ·
                            {{ fmt(referee.avg_yellows_per_match, 1) }} yellows ·
                            {{ fmt(referee.avg_reds_per_match, 2) }} reds ·
                            {{ fmt(referee.avg_fouls_per_match, 1) }} fouls / match
                        </p>
                    </template>
                    <p v-else class="text-sm text-slate-400">
                        Not assigned yet — cards model uses the league-average referee
                        (lower confidence on cards picks).
                    </p>
                </div>
            </div>
        </section>

        <!-- Head to head -->
        <section v-if="head_to_head.length" class="mb-6">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-widest text-slate-400">
                Recent meetings
            </h2>
            <ul class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900">
                <li
                    v-for="(meeting, index) in head_to_head"
                    :key="index"
                    class="flex items-center justify-between border-b border-slate-800/60 px-4 py-2.5 text-sm last:border-b-0"
                >
                    <span class="text-slate-400">{{ meeting.date }}</span>
                    <span class="font-semibold">
                        {{ meeting.home }}
                        <span class="mx-1 font-mono text-green-400">{{ meeting.home_goals }}–{{ meeting.away_goals }}</span>
                        {{ meeting.away }}
                    </span>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
