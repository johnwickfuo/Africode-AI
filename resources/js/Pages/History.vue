<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import EmptyState from '../Components/EmptyState.vue';
import OutcomeBadge from '../Components/OutcomeBadge.vue';
import PageHeader from '../Components/PageHeader.vue';
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

const selectClass =
    'w-full appearance-none rounded-xl border border-ink-700 bg-ink-850 px-3 py-2.5 text-sm font-medium text-ink-100 transition hover:border-ink-600 focus:border-brand-500 focus:outline-none';
</script>

<template>
    <Head title="History" />

    <AppLayout>
        <PageHeader
            eyebrow="Settled picks"
            title="Prediction history"
            subtitle="Every pick the model has published, scored against the final match stats — winners and losers alike."
        />

        <!-- Filters -->
        <div class="mb-6 grid grid-cols-2 gap-3">
            <label class="block">
                <span class="label mb-1.5 block">Market</span>
                <select
                    :class="selectClass"
                    :value="filters.market ?? ''"
                    @change="applyFilter('market', $event.target.value)"
                >
                    <option value="">All markets</option>
                    <option v-for="market in markets" :key="market" :value="market">
                        {{ marketLabel(market) }}
                    </option>
                </select>
            </label>
            <label class="block">
                <span class="label mb-1.5 block">League</span>
                <select
                    :class="selectClass"
                    :value="filters.league ?? ''"
                    @change="applyFilter('league', $event.target.value)"
                >
                    <option value="">All leagues</option>
                    <option v-for="league in leagues" :key="league.code" :value="league.code">
                        {{ league.name }}
                    </option>
                </select>
            </label>
        </div>

        <template v-if="rows.data.length">
            <ul class="space-y-2.5">
                <li v-for="row in rows.data" :key="row.id">
                    <Link
                        :href="`/match/${row.fixture_id}`"
                        class="card card-interactive flex items-center justify-between gap-3 p-4"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-ink-100">
                                {{ marketLabel(row.market) }}: {{ lineLabel(row) }}
                                <span class="ml-1 font-mono text-xs text-ink-400">
                                    {{ Math.round(row.probability * 100) }}%
                                </span>
                            </p>
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-ink-500">
                                <span class="tag bg-ink-800 !text-brand-400">{{ row.league }}</span>
                                <span class="truncate">{{ row.match }}</span>
                                <span class="text-ink-600">·</span>
                                <span>{{ row.settled_at }}</span>
                            </p>
                        </div>
                        <OutcomeBadge :outcome="row.outcome" />
                    </Link>
                </li>
            </ul>

            <nav
                v-if="rows.prev_page_url || rows.next_page_url"
                class="mt-6 flex items-center justify-between gap-3"
                aria-label="Pagination"
            >
                <Link
                    v-if="rows.prev_page_url"
                    :href="rows.prev_page_url"
                    class="chip chip-idle"
                    preserve-scroll
                >
                    ← Newer
                </Link>
                <span v-else />
                <span class="text-xs text-ink-500">
                    Page {{ rows.current_page }} of {{ rows.last_page }}
                </span>
                <Link
                    v-if="rows.next_page_url"
                    :href="rows.next_page_url"
                    class="chip chip-idle"
                    preserve-scroll
                >
                    Older →
                </Link>
                <span v-else />
            </nav>
        </template>

        <EmptyState
            v-else
            icon="chart"
            :title="`No settled predictions${filters.market || filters.league ? ' for these filters' : ' yet'}`"
            message="Picks land here once their matches finish and the overnight scoring runs."
        />
    </AppLayout>
</template>
