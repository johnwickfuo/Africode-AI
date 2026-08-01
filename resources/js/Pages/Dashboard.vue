<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import EmptyState from '../Components/EmptyState.vue';
import PageHeader from '../Components/PageHeader.vue';
import ProbabilityBar from '../Components/ProbabilityBar.vue';
import TeamCrest from '../Components/TeamCrest.vue';

const props = defineProps({
    leagues: { type: Array, default: () => [] },
    fixtures: { type: Array, default: () => [] },
});

const activeLeague = ref(null);
const valueOnly = ref(false);

const filteredFixtures = computed(() =>
    props.fixtures.filter(
        (fixture) =>
            (!activeLeague.value || fixture.league.code === activeLeague.value)
            && (!valueOnly.value || fixture.value),
    ),
);

const fixturesByDate = computed(() => {
    const groups = new Map();
    for (const fixture of filteredFixtures.value) {
        if (!groups.has(fixture.kickoff_date)) groups.set(fixture.kickoff_date, []);
        groups.get(fixture.kickoff_date).push(fixture);
    }
    return [...groups.entries()].map(([date, fixtures]) => ({ date, fixtures }));
});

const valueCount = computed(() => props.fixtures.filter((fixture) => fixture.value).length);
const predictedCount = computed(() => props.fixtures.filter((fixture) => fixture.best_bet).length);

const leagueCount = (code) => props.fixtures.filter((fixture) => fixture.league.code === code).length;

const confidence = (probability) => {
    if (probability >= 0.78) return { label: 'High', class: 'bg-brand-500/15 text-brand-300' };
    if (probability >= 0.7) return { label: 'Solid', class: 'bg-brand-500/10 text-brand-400' };
    return { label: 'Lean', class: 'bg-ink-700/50 text-ink-300' };
};
</script>

<template>
    <Head title="Fixtures" />

    <AppLayout>
        <PageHeader
            eyebrow="Next 14 days"
            title="Fixtures & Best Bets"
            subtitle="Every upcoming match in Europe's top five leagues, with the model's strongest call on each one."
        />

        <!-- Snapshot -->
        <div class="card mb-6 grid grid-cols-3 divide-x divide-ink-800/70 sm:max-w-lg">
            <div class="px-4 py-3">
                <p class="label">Fixtures</p>
                <p class="mt-0.5 text-xl font-bold text-ink-100" data-nums>{{ fixtures.length }}</p>
            </div>
            <div class="px-4 py-3">
                <p class="label">Predicted</p>
                <p class="mt-0.5 text-xl font-bold text-ink-100" data-nums>{{ predictedCount }}</p>
            </div>
            <div class="px-4 py-3">
                <p class="label" :class="valueCount ? '!text-value-400' : ''">Value</p>
                <p
                    class="mt-0.5 text-xl font-bold"
                    :class="valueCount ? 'text-value-400' : 'text-ink-100'"
                    data-nums
                >
                    {{ valueCount }}
                </p>
            </div>
        </div>

        <!-- Filters -->
        <div class="-mx-4 mb-6 overflow-x-auto px-4 no-scrollbar sm:mx-0 sm:px-0">
            <nav class="flex w-max gap-2" aria-label="League filter">
                <button
                    type="button"
                    class="chip"
                    :class="activeLeague === null && !valueOnly ? 'chip-active' : 'chip-idle'"
                    @click="activeLeague = null; valueOnly = false"
                >
                    All matches
                </button>
                <button
                    v-for="league in leagues"
                    :key="league.id"
                    type="button"
                    class="chip"
                    :class="activeLeague === league.code ? 'chip-active' : 'chip-idle'"
                    @click="activeLeague = activeLeague === league.code ? null : league.code"
                >
                    {{ league.name }}
                    <span class="text-xs opacity-60" data-nums>{{ leagueCount(league.code) }}</span>
                </button>
                <button
                    type="button"
                    class="chip"
                    :class="valueOnly
                        ? 'bg-value-500 text-ink-950'
                        : 'bg-ink-800 text-value-400 hover:bg-ink-750'"
                    :aria-pressed="valueOnly"
                    @click="valueOnly = !valueOnly"
                >
                    ◆ Value only
                </button>
            </nav>
        </div>

        <!-- Fixtures -->
        <template v-if="fixturesByDate.length">
            <section v-for="group in fixturesByDate" :key="group.date" class="mb-7">
                <h2 class="sticky top-16 z-20 -mx-4 mb-3 bg-ink-950/85 px-4 py-2 text-[11px] font-bold uppercase tracking-[.14em] text-ink-400 backdrop-blur sm:mx-0 sm:rounded-lg sm:px-3">
                    {{ group.date }}
                </h2>

                <ul class="grid gap-2.5 lg:grid-cols-2">
                    <li v-for="fixture in group.fixtures" :key="fixture.id">
                        <Link :href="`/match/${fixture.id}`" class="card card-interactive block p-4">
                            <!-- Meta row -->
                            <div class="mb-3.5 flex items-center gap-2 text-xs">
                                <span class="tag bg-ink-800 !text-brand-400">{{ fixture.league.code }}</span>
                                <span v-if="fixture.matchday" class="text-ink-500">MD {{ fixture.matchday }}</span>
                                <span v-if="fixture.is_derby" class="tag bg-amber-500/15 text-amber-300">Derby</span>
                                <span
                                    v-if="fixture.value"
                                    class="tag bg-value-500/15 text-value-400"
                                    :title="`The model rates this pick ${(fixture.value.edge * 100).toFixed(0)} points higher than the market`"
                                >
                                    ◆ Value +{{ (fixture.value.edge * 100).toFixed(0) }}%
                                </span>
                                <span class="ml-auto shrink-0 font-mono text-sm font-semibold text-ink-200">
                                    {{ fixture.kickoff_time }}
                                </span>
                            </div>

                            <!-- Teams: stacked scoreboard reads cleanly on narrow phones -->
                            <div class="space-y-2">
                                <div class="flex items-center gap-3">
                                    <TeamCrest :team="fixture.home_team" />
                                    <span class="min-w-0 flex-1 truncate font-semibold text-ink-100">
                                        {{ fixture.home_team.name }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <TeamCrest :team="fixture.away_team" />
                                    <span class="min-w-0 flex-1 truncate font-semibold text-ink-100">
                                        {{ fixture.away_team.name }}
                                    </span>
                                </div>
                            </div>

                            <!-- Best bet -->
                            <div v-if="fixture.best_bet" class="surface-sunken mt-3.5 p-3">
                                <div class="mb-2 flex items-start justify-between gap-3">
                                    <p class="min-w-0 text-sm font-semibold leading-snug text-brand-300">
                                        <span aria-hidden="true">★</span> {{ fixture.best_bet.headline }}
                                    </p>
                                    <span
                                        class="tag shrink-0"
                                        :class="confidence(fixture.best_bet.probability).class"
                                    >
                                        {{ confidence(fixture.best_bet.probability).label }}
                                    </span>
                                </div>
                                <ProbabilityBar :probability="fixture.best_bet.probability" />
                            </div>
                            <p v-else class="mt-3.5 text-xs text-ink-500">
                                Prediction publishes each morning at 06:00.
                            </p>
                        </Link>
                    </li>
                </ul>
            </section>
        </template>

        <EmptyState
            v-else-if="fixtures.length"
            title="Nothing matches these filters"
            message="Try another league, or switch off the value filter to see every upcoming match."
        />

        <EmptyState
            v-else
            title="No fixtures scheduled yet"
            message="Matches appear here as soon as the leagues publish their schedule — usually a couple of weeks before kick-off. Predictions follow the morning after."
        />
    </AppLayout>
</template>
