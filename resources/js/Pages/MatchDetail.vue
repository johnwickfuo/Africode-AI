<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import ProbabilityBar from '../Components/ProbabilityBar.vue';
import SectionHeading from '../Components/SectionHeading.vue';
import Sparkline from '../Components/Sparkline.vue';
import TeamCrest from '../Components/TeamCrest.vue';
import { MARKET_SECTIONS, lineLabel } from '../lib/markets';

const props = defineProps({
    fixture: { type: Object, required: true },
    referee: { type: Object, default: null },
    prediction: { type: Object, default: null },
    value_bets: { type: Array, default: () => [] },
    odds: { type: Object, default: null },
    profiles: { type: Object, required: true },
    xg_trend: { type: Object, required: true },
    head_to_head: { type: Array, default: () => [] },
    key_players: { type: Object, default: () => ({ home: null, away: null }) },
});

const keyPlayerRows = [
    ['top_scorer', 'Top scorer', 'goals'],
    ['top_assister', 'Top assister', 'assists'],
    ['most_carded', 'Most carded', 'cards'],
];

const sections = computed(() => {
    if (!props.prediction) return [];
    return MARKET_SECTIONS.map((section) => ({
        title: section.title,
        hint: section.hint ?? null,
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

const pctText = (value) => `${(value * 100).toFixed(0)}%`;

const valueMarketLabel = (row) => {
    if (row.market === 'goals_2.5') {
        return `${row.pick === 'over' ? 'Over' : 'Under'} 2.5 goals`;
    }
    if (row.pick === 'draw') return 'Draw';
    return `${row.pick === 'home' ? props.fixture.home_team.name : props.fixture.away_team.name} win`;
};

const pageTitle = computed(
    () => `${props.fixture.home_team.short_name} v ${props.fixture.away_team.short_name}`,
);

const isFinished = computed(() => props.fixture.status === 'finished');
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout>
        <!-- Match header -->
        <section class="card mb-6 animate-fade-up p-5">
            <div class="mb-4 flex flex-wrap items-center gap-2 text-xs">
                <span class="tag bg-ink-800 !text-brand-400">{{ fixture.league.code }}</span>
                <span class="text-ink-500">{{ fixture.league.name }}</span>
                <span v-if="fixture.matchday" class="text-ink-500">· MD {{ fixture.matchday }}</span>
                <span v-if="fixture.is_derby" class="tag bg-amber-500/15 text-amber-300">Derby</span>
            </div>

            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3 sm:gap-6">
                <div class="flex min-w-0 flex-col items-center gap-2 text-center">
                    <TeamCrest :team="fixture.home_team" size="lg" />
                    <span class="line-clamp-2 text-sm font-bold leading-tight text-ink-100 sm:text-base">
                        {{ fixture.home_team.name }}
                    </span>
                </div>

                <div class="shrink-0 text-center">
                    <p v-if="isFinished" class="font-mono text-3xl font-black text-brand-400 sm:text-4xl">
                        {{ fixture.home_goals }}<span class="mx-1 text-ink-600">–</span>{{ fixture.away_goals }}
                    </p>
                    <p v-else class="font-mono text-2xl font-bold text-ink-200 sm:text-3xl">
                        {{ fixture.kickoff_time }}
                    </p>
                    <p class="mt-1 text-[11px] uppercase tracking-wider text-ink-500">
                        {{ isFinished ? 'Full time' : 'Kick-off' }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-col items-center gap-2 text-center">
                    <TeamCrest :team="fixture.away_team" size="lg" />
                    <span class="line-clamp-2 text-sm font-bold leading-tight text-ink-100 sm:text-base">
                        {{ fixture.away_team.name }}
                    </span>
                </div>
            </div>

            <p class="mt-4 border-t border-ink-800/70 pt-3 text-center text-sm text-ink-400">
                {{ fixture.kickoff_date }}
            </p>
        </section>

        <template v-if="prediction">
            <!-- Best Bet -->
            <section class="relative mb-6 animate-fade-up overflow-hidden rounded-2xl border border-brand-500/35 bg-brand-500/[.07] p-5 shadow-glow">
                <div class="flex items-center gap-2">
                    <span class="tag bg-brand-500/20 text-brand-300">★ Best Bet</span>
                </div>
                <p class="mt-3 text-xl font-bold leading-snug tracking-tight text-ink-100 sm:text-2xl">
                    {{ prediction.best_bet.headline }}
                </p>
                <div class="mt-4">
                    <ProbabilityBar :probability="prediction.best_bet.probability" size="md" />
                </div>
                <p class="mt-3 text-xs text-ink-500">
                    Model {{ prediction.model_version }} · generated {{ prediction.generated_at }}
                </p>
            </section>

            <!-- Model vs market -->
            <section v-if="value_bets.length" class="mb-6">
                <SectionHeading
                    title="Model vs Market"
                    hint="Bookmaker prices with the margin stripped out. A positive edge means the model rates the pick higher than the market does."
                />
                <ul class="space-y-2.5">
                    <li
                        v-for="row in value_bets"
                        :key="row.market"
                        class="card p-4"
                        :class="row.is_value ? '!border-value-500/40' : ''"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-ink-100">
                                    {{ valueMarketLabel(row) }}
                                </p>
                                <p class="mt-0.5 font-mono text-xs text-ink-500">
                                    odds {{ row.odds.toFixed(2) }}
                                </p>
                            </div>
                            <span
                                class="tag shrink-0 text-xs"
                                :class="row.is_value
                                    ? 'bg-value-500/15 text-value-400'
                                    : row.edge >= 0 ? 'bg-ink-800 text-ink-300' : 'bg-ink-800 text-ink-500'"
                            >
                                {{ row.edge >= 0 ? '+' : '' }}{{ (row.edge * 100).toFixed(1) }}%
                                <template v-if="row.is_value">value</template>
                            </span>
                        </div>

                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <div class="surface-sunken px-3 py-2">
                                <p class="label">Market</p>
                                <p class="mt-0.5 font-mono text-sm font-semibold text-ink-200">
                                    {{ pctText(row.implied_probability) }}
                                </p>
                            </div>
                            <div class="surface-sunken px-3 py-2">
                                <p class="label">Model</p>
                                <p class="mt-0.5 font-mono text-sm font-semibold text-brand-300">
                                    {{ pctText(row.model_probability) }}
                                </p>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Markets -->
            <section v-for="section in sections" :key="section.title" class="mb-6">
                <SectionHeading :title="section.title" :hint="section.hint" />
                <div class="card divide-y divide-ink-800/70">
                    <div
                        v-for="row in section.rows"
                        :key="`${row.market}-${row.line}-${row.direction}`"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <span class="w-28 shrink-0 truncate text-sm font-semibold text-ink-200 sm:w-44">
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

        <div v-else class="card mb-6 px-6 py-10 text-center">
            <p class="font-semibold text-ink-100">No prediction for this fixture yet.</p>
            <p class="mx-auto mt-2 max-w-sm text-sm text-ink-400">
                Predictions publish each morning at 06:00 for matches inside the next seven days.
            </p>
        </div>

        <!-- Model inputs -->
        <section class="mb-6">
            <SectionHeading title="Model inputs" hint="Rolling averages, weighted toward recent matches." />
            <div class="card overflow-x-auto">
                <table class="w-full min-w-[22rem] text-sm">
                    <thead>
                        <tr class="border-b border-ink-800 text-[11px] uppercase tracking-wider text-ink-500">
                            <th class="px-4 py-3 text-left font-semibold">Rolling stat</th>
                            <th class="px-3 py-3 text-right font-semibold">{{ fixture.home_team.short_name }}</th>
                            <th class="px-4 py-3 text-right font-semibold">{{ fixture.away_team.short_name }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-800/60">
                        <tr v-for="[label, key, decimals] in profileRows" :key="key">
                            <td class="px-4 py-2.5 text-ink-400">{{ label }}</td>
                            <td class="px-3 py-2.5 text-right font-mono text-ink-100">
                                {{ fmt(profiles.home?.[key], decimals) }}
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono text-ink-100">
                                {{ fmt(profiles.away?.[key], decimals) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div class="card p-4">
                    <p class="label mb-3">xG trend (last {{ xg_trend.home.length }})</p>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-medium text-ink-300">{{ fixture.home_team.short_name }}</span>
                            <Sparkline :values="xg_trend.home" :label="fixture.home_team.name" />
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-medium text-ink-300">{{ fixture.away_team.short_name }}</span>
                            <Sparkline :values="xg_trend.away" :label="fixture.away_team.name" />
                        </div>
                    </div>
                </div>

                <div class="card p-4">
                    <p class="label mb-3">Referee</p>
                    <template v-if="referee">
                        <p class="font-semibold text-ink-100">{{ referee.name }}</p>
                        <p class="mt-1.5 text-sm leading-relaxed text-ink-400">
                            {{ referee.matches_officiated }} matches ·
                            {{ fmt(referee.avg_yellows_per_match, 1) }} yellows ·
                            {{ fmt(referee.avg_reds_per_match, 2) }} reds ·
                            {{ fmt(referee.avg_fouls_per_match, 1) }} fouls / match
                        </p>
                    </template>
                    <p v-else class="text-sm leading-relaxed text-ink-400">
                        Not assigned yet — the cards model falls back to the league-average
                        referee, so cards picks carry lower confidence.
                    </p>
                </div>
            </div>
        </section>

        <!-- Key players -->
        <section class="mb-6">
            <SectionHeading :title="`Key players (${fixture.season})`" />
            <div v-if="key_players.home || key_players.away" class="grid gap-3 sm:grid-cols-2">
                <div v-for="side in ['home', 'away']" :key="side" class="card p-4">
                    <div class="mb-3 flex items-center gap-2.5">
                        <TeamCrest :team="fixture[`${side}_team`]" />
                        <p class="truncate text-sm font-semibold text-ink-100">
                            {{ fixture[`${side}_team`].name }}
                        </p>
                    </div>
                    <ul v-if="key_players[side]" class="space-y-2 text-sm">
                        <li
                            v-for="[key, label, unit] in keyPlayerRows"
                            :key="key"
                            class="flex items-center justify-between gap-2"
                        >
                            <span class="text-ink-400">{{ label }}</span>
                            <span v-if="key_players[side][key]" class="min-w-0 truncate font-semibold text-ink-100">
                                {{ key_players[side][key].name }}
                                <span class="font-mono text-brand-400">{{ key_players[side][key].value }}</span>
                                <span class="ml-0.5 text-xs text-ink-500">{{ unit }}</span>
                            </span>
                            <span v-else class="text-ink-600">—</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-ink-500">Player data still loading for this team.</p>
                </div>
            </div>
            <p v-else class="card px-4 py-6 text-center text-sm text-ink-500">
                Player data is still being collected — key players appear as match reports arrive.
            </p>
        </section>

        <!-- Head to head -->
        <section v-if="head_to_head.length" class="mb-6">
            <SectionHeading title="Recent meetings" />
            <ul class="card divide-y divide-ink-800/70">
                <li
                    v-for="(meeting, index) in head_to_head"
                    :key="index"
                    class="flex items-center justify-between gap-3 px-4 py-3 text-sm"
                >
                    <span class="shrink-0 text-ink-500">{{ meeting.date }}</span>
                    <span class="min-w-0 truncate text-right font-semibold text-ink-100">
                        {{ meeting.home }}
                        <span class="mx-1 font-mono text-brand-400">
                            {{ meeting.home_goals }}–{{ meeting.away_goals }}
                        </span>
                        {{ meeting.away }}
                    </span>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
