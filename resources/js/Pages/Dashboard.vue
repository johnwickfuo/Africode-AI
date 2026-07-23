<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

const props = defineProps({
    leagues: {
        type: Array,
        default: () => [],
    },
    fixtures: {
        type: Array,
        default: () => [],
    },
});

const activeLeague = ref(null);

const filteredFixtures = computed(() =>
    activeLeague.value
        ? props.fixtures.filter((fixture) => fixture.league.code === activeLeague.value)
        : props.fixtures,
);

const fixturesByDate = computed(() => {
    const groups = new Map();
    for (const fixture of filteredFixtures.value) {
        if (!groups.has(fixture.kickoff_date)) {
            groups.set(fixture.kickoff_date, []);
        }
        groups.get(fixture.kickoff_date).push(fixture);
    }
    return [...groups.entries()].map(([date, fixtures]) => ({ date, fixtures }));
});

const confidence = (probability) => {
    if (probability >= 0.78) return { label: 'High', class: 'bg-green-500/15 text-green-400' };
    if (probability >= 0.7) return { label: 'Solid', class: 'bg-green-500/10 text-green-500' };
    return { label: 'Lean', class: 'bg-slate-700/40 text-slate-300' };
};
</script>

<template>
    <Head title="Fixtures" />

    <AppLayout>
        <nav class="mb-6 flex flex-wrap gap-2" aria-label="League filter">
            <button
                type="button"
                class="rounded-full px-3 py-1.5 text-sm font-semibold transition"
                :class="activeLeague === null
                    ? 'bg-green-500 text-slate-950'
                    : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                @click="activeLeague = null"
            >
                All
            </button>
            <button
                v-for="league in leagues"
                :key="league.id"
                type="button"
                class="rounded-full px-3 py-1.5 text-sm font-semibold transition"
                :class="activeLeague === league.code
                    ? 'bg-green-500 text-slate-950'
                    : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                @click="activeLeague = league.code"
            >
                {{ league.name }}
            </button>
        </nav>

        <template v-if="fixturesByDate.length">
            <section v-for="group in fixturesByDate" :key="group.date" class="mb-8">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-widest text-slate-400">
                    {{ group.date }}
                </h2>
                <ul class="space-y-2">
                    <li v-for="fixture in group.fixtures" :key="fixture.id">
                        <Link
                            :href="`/match/${fixture.id}`"
                            class="block rounded-xl border border-slate-800 bg-slate-900 p-4 transition hover:border-slate-600"
                        >
                            <div class="mb-2 flex items-center justify-between text-xs text-slate-400">
                                <span class="flex items-center gap-2">
                                    <span class="rounded bg-slate-800 px-2 py-0.5 font-bold text-green-400">
                                        {{ fixture.league.code }}
                                    </span>
                                    <span v-if="fixture.matchday">MD {{ fixture.matchday }}</span>
                                    <span
                                        v-if="fixture.is_derby"
                                        class="rounded bg-amber-500/15 px-2 py-0.5 font-bold text-amber-400"
                                    >
                                        DERBY
                                    </span>
                                    <span
                                        v-if="fixture.value"
                                        class="rounded bg-sky-500/15 px-2 py-0.5 font-bold text-sky-400"
                                        :title="`Model edge +${(fixture.value.edge * 100).toFixed(0)}% vs the market`"
                                    >
                                        VALUE +{{ (fixture.value.edge * 100).toFixed(0) }}%
                                    </span>
                                </span>
                                <span class="font-mono font-semibold text-slate-300">
                                    {{ fixture.kickoff_time }}
                                </span>
                            </div>

                            <div class="flex items-center justify-between gap-3 font-semibold">
                                <span class="flex-1 truncate text-right">{{ fixture.home_team.name }}</span>
                                <span class="text-xs font-bold text-slate-500">vs</span>
                                <span class="flex-1 truncate">{{ fixture.away_team.name }}</span>
                            </div>

                            <div
                                v-if="fixture.best_bet"
                                class="mt-3 flex items-center justify-between gap-2 rounded-lg bg-slate-950/60 px-3 py-2"
                            >
                                <span class="truncate text-sm font-semibold text-green-400">
                                    ★ {{ fixture.best_bet.headline }}
                                </span>
                                <span
                                    class="shrink-0 rounded px-2 py-0.5 text-xs font-bold"
                                    :class="confidence(fixture.best_bet.probability).class"
                                >
                                    {{ confidence(fixture.best_bet.probability).label }}
                                </span>
                            </div>
                            <p v-else class="mt-3 text-xs text-slate-500">
                                Prediction pending — generated daily at 06:00.
                            </p>
                        </Link>
                    </li>
                </ul>
            </section>
        </template>

        <div
            v-else
            class="rounded-xl border border-dashed border-slate-700 p-8 text-center text-slate-400"
        >
            <p class="font-semibold">No upcoming fixtures.</p>
            <p class="mt-2 text-sm">
                Run
                <code class="rounded bg-slate-800 px-1.5 py-0.5 text-green-400">
                    php artisan africode:sync-fixtures
                </code>
                to pull fixtures from football-data.org.
            </p>
        </div>
    </AppLayout>
</template>
