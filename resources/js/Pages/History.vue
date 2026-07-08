<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import OutcomeBadge from '../Components/OutcomeBadge.vue';
import { lineLabel, marketLabel } from '../lib/markets';

const props = defineProps({
    rows: { type: Object, required: true },
    filters: { type: Object, required: true },
    markets: { type: Array, default: () => [] },
    leagues: { type: Array, default: () => [] },
});

const applyFilter = (key, value) => {
    router.get(
        '/history',
        { ...props.filters, [key]: value || undefined },
        { preserveState: true, preserveScroll: true },
    );
};
</script>

<template>
    <Head title="History" />

    <AppLayout>
        <h1 class="mb-1 text-xl font-bold">Settled predictions</h1>
        <p class="mb-5 text-sm text-slate-400">Every pick, scored against the final stats.</p>

        <div class="mb-6 flex flex-wrap gap-3">
            <label class="flex items-center gap-2 text-sm text-slate-400">
                Market
                <select
                    class="rounded-lg border border-slate-700 bg-slate-900 px-2 py-1.5 text-sm text-slate-200"
                    :value="filters.market ?? ''"
                    @change="applyFilter('market', $event.target.value)"
                >
                    <option value="">All</option>
                    <option v-for="market in markets" :key="market" :value="market">
                        {{ marketLabel(market) }}
                    </option>
                </select>
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-400">
                League
                <select
                    class="rounded-lg border border-slate-700 bg-slate-900 px-2 py-1.5 text-sm text-slate-200"
                    :value="filters.league ?? ''"
                    @change="applyFilter('league', $event.target.value)"
                >
                    <option value="">All</option>
                    <option v-for="league in leagues" :key="league.code" :value="league.code">
                        {{ league.name }}
                    </option>
                </select>
            </label>
        </div>

        <template v-if="rows.data.length">
            <ul class="space-y-2">
                <li v-for="row in rows.data" :key="row.id">
                    <Link
                        :href="`/match/${row.fixture_id}`"
                        class="flex items-center justify-between gap-3 rounded-xl border border-slate-800 bg-slate-900 p-3.5 transition hover:border-slate-600"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">
                                {{ marketLabel(row.market) }}:
                                {{ lineLabel(row) }}
                                <span class="font-mono text-slate-400">({{ Math.round(row.probability * 100) }}%)</span>
                            </p>
                            <p class="mt-0.5 truncate text-xs text-slate-400">
                                <span class="font-bold text-green-400">{{ row.league }}</span>
                                · {{ row.match }} · {{ row.settled_at }}
                            </p>
                        </div>
                        <OutcomeBadge :outcome="row.outcome" />
                    </Link>
                </li>
            </ul>

            <nav
                v-if="rows.prev_page_url || rows.next_page_url"
                class="mt-6 flex items-center justify-between text-sm"
                aria-label="Pagination"
            >
                <Link
                    v-if="rows.prev_page_url"
                    :href="rows.prev_page_url"
                    class="rounded-lg bg-slate-800 px-3 py-1.5 font-semibold text-slate-200 hover:bg-slate-700"
                    preserve-scroll
                >
                    ← Newer
                </Link>
                <span v-else />
                <span class="text-xs text-slate-500">Page {{ rows.current_page }} of {{ rows.last_page }}</span>
                <Link
                    v-if="rows.next_page_url"
                    :href="rows.next_page_url"
                    class="rounded-lg bg-slate-800 px-3 py-1.5 font-semibold text-slate-200 hover:bg-slate-700"
                    preserve-scroll
                >
                    Older →
                </Link>
            </nav>
        </template>

        <div
            v-else
            class="rounded-xl border border-dashed border-slate-700 p-8 text-center text-slate-400"
        >
            <p class="font-semibold">No settled predictions{{ filters.market || filters.league ? ' for these filters' : '' }} yet.</p>
            <p class="mt-2 text-sm">
                Picks appear here once fixtures finish and the settlement job scores them.
            </p>
        </div>
    </AppLayout>
</template>
