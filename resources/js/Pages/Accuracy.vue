<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import AccaTicket from '../Components/AccaTicket.vue';
import EmptyState from '../Components/EmptyState.vue';
import PageHeader from '../Components/PageHeader.vue';
import SectionHeading from '../Components/SectionHeading.vue';
import StatTile from '../Components/StatTile.vue';
import { marketLabel } from '../lib/markets';

const props = defineProps({
    windows: { type: Object, required: true },
    model_versions: { type: Array, default: () => [] },
    result_models: { type: Array, default: () => [] },
    accumulators: { type: Array, default: () => [] },
});

const ticketName = (ticket) =>
    ticket.max_leg_odds ? `Max ${ticket.max_leg_odds.toFixed(2)} per leg` : 'Classic';

const windowOptions = [
    { key: '30', label: 'Last 30 days' },
    { key: '90', label: 'Last 90 days' },
    { key: 'all', label: 'All time' },
];

const activeWindow = ref('30');
const stats = computed(() => props.windows[activeWindow.value]);

const pct = (value) => (value === null || value === undefined ? '—' : `${(value * 100).toFixed(1)}%`);
const bar = (value) => `${Math.min(Math.max((value ?? 0) * 100, 0), 100).toFixed(1)}%`;

const gapClass = (gap) => {
    if (gap === null || gap === undefined) return 'bg-ink-700/50 text-ink-300';
    if (Math.abs(gap) <= 0.05) return 'bg-brand-500/15 text-brand-300';
    return gap > 0 ? 'bg-rose-500/15 text-rose-300' : 'bg-amber-500/15 text-amber-300';
};
</script>

<template>
    <Head title="Accuracy" />

    <AppLayout>
        <PageHeader
            eyebrow="Public record"
            title="Accuracy tracker"
            subtitle="Every settled pick, scored against the final stats. Nothing is hidden or retro-fitted — this is the model marking its own homework in public."
        />

        <div class="-mx-4 mb-6 overflow-x-auto px-4 no-scrollbar sm:mx-0 sm:px-0">
            <nav class="flex w-max gap-2" aria-label="Time window">
                <button
                    v-for="option in windowOptions"
                    :key="option.key"
                    type="button"
                    class="chip"
                    :class="activeWindow === option.key ? 'chip-active' : 'chip-idle'"
                    @click="activeWindow = option.key"
                >
                    {{ option.label }}
                </button>
            </nav>
        </div>

        <template v-if="stats.total_settled > 0">
            <div class="mb-7 grid grid-cols-2 gap-2.5 sm:gap-3 lg:grid-cols-4">
                <StatTile
                    label="Best Bet win rate"
                    :value="pct(stats.best_bets.hit_rate)"
                    :hint="`${stats.best_bets.hits} of ${stats.best_bets.total} won`"
                    tone="brand"
                />
                <StatTile
                    label="Claimed average"
                    :value="pct(stats.best_bets.avg_probability)"
                    hint="what the model promised"
                />
                <StatTile
                    label="Settled picks"
                    :value="stats.total_settled"
                    hint="across all markets"
                />
                <StatTile
                    label="Brier score"
                    :value="stats.scores.brier ?? '—'"
                    :hint="`log-loss ${stats.scores.log_loss ?? '—'} · lower is better`"
                />
            </div>

            <!-- Per-market -->
            <section class="mb-7">
                <SectionHeading
                    title="Hit rate by market"
                    hint="How often each market's picks landed, next to the probability the model claimed."
                />
                <ul class="grid gap-2.5 sm:grid-cols-2">
                    <li v-for="market in stats.markets" :key="market.market" class="card p-4">
                        <div class="mb-2.5 flex items-baseline justify-between gap-3">
                            <p class="min-w-0 truncate text-sm font-semibold text-ink-100">
                                {{ marketLabel(market.market) }}
                            </p>
                            <p class="shrink-0 font-mono text-lg font-bold text-ink-100">
                                {{ pct(market.hit_rate) }}
                            </p>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-ink-800">
                            <div
                                class="h-full origin-left animate-bar-grow rounded-full bg-brand-gradient"
                                :style="{ width: bar(market.hit_rate) }"
                            />
                        </div>
                        <div class="mt-2 flex justify-between text-xs text-ink-500">
                            <span>{{ market.hits }} of {{ market.total }} won</span>
                            <span>claimed {{ pct(market.avg_probability) }}</span>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Calibration -->
            <section class="mb-7">
                <SectionHeading
                    title="Calibration"
                    hint="Within each claimed-probability band, did the picks actually win that often? A gap near zero means the percentages can be trusted at face value."
                />
                <ul class="grid gap-2.5 sm:grid-cols-2">
                    <li v-for="bucket in stats.calibration" :key="bucket.bucket" class="card p-4">
                        <div class="mb-2.5 flex items-center justify-between gap-3">
                            <span class="font-mono text-sm font-semibold text-ink-100">{{ bucket.bucket }}</span>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-ink-500">{{ bucket.total }} picks</span>
                                <span class="tag" :class="gapClass(bucket.gap)">
                                    {{ bucket.gap > 0 ? '+' : '' }}{{ (bucket.gap * 100).toFixed(1) }}pp
                                </span>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-center gap-2.5">
                                <span class="w-14 shrink-0 text-[11px] text-ink-500">Claimed</span>
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-ink-800">
                                    <div class="h-full rounded-full bg-ink-500" :style="{ width: bar(bucket.avg_probability) }" />
                                </div>
                                <span class="w-11 shrink-0 text-right font-mono text-[11px] text-ink-400">
                                    {{ pct(bucket.avg_probability) }}
                                </span>
                            </div>
                            <div class="flex items-center gap-2.5">
                                <span class="w-14 shrink-0 text-[11px] text-ink-500">Actual</span>
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-ink-800">
                                    <div
                                        class="h-full origin-left animate-bar-grow rounded-full bg-brand-gradient"
                                        :style="{ width: bar(bucket.hit_rate) }"
                                    />
                                </div>
                                <span class="w-11 shrink-0 text-right font-mono text-[11px] font-semibold text-ink-100">
                                    {{ pct(bucket.hit_rate) }}
                                </span>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>
        </template>

        <EmptyState
            v-else
            icon="chart"
            title="No settled picks in this window yet"
            message="Accuracy builds up as matches finish and each pick is scored overnight. Try a wider window, or check back after the weekend."
            class="mb-7"
        />

        <!-- Champion vs challenger -->
        <section v-if="result_models.length" class="mb-7">
            <SectionHeading
                title="Match result: model vs model"
                hint="Two models predict every result independently. The challenger's picks never appear on the site — this is purely the referee."
            />
            <div class="grid gap-3 sm:grid-cols-2">
                <div
                    v-for="model in result_models"
                    :key="model.version"
                    class="card p-4"
                    :class="model.challenger ? '!border-violet-500/30' : '!border-brand-500/30'"
                >
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <span
                            class="tag"
                            :class="model.challenger
                                ? 'bg-violet-500/15 text-violet-300'
                                : 'bg-brand-500/15 text-brand-300'"
                        >
                            {{ model.challenger ? 'Challenger' : 'Champion' }}
                        </span>
                        <span class="font-mono text-xs text-ink-500">{{ model.version }}</span>
                    </div>
                    <p class="font-mono text-3xl font-bold text-ink-100">{{ pct(model.hit_rate) }}</p>
                    <p class="mt-1 text-xs text-ink-500">
                        {{ model.hits }} of {{ model.total }} correct · claimed {{ pct(model.avg_probability) }}
                    </p>
                    <div class="surface-sunken mt-3 flex items-center justify-between px-3 py-2">
                        <span class="label">Brier</span>
                        <span class="font-mono text-sm font-semibold text-ink-100">{{ model.brier }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Model versions -->
        <section v-if="model_versions.length" class="mb-7">
            <SectionHeading title="Model versions" hint="All settled picks, grouped by the model that made them." />
            <ul class="card divide-y divide-ink-800/70">
                <li
                    v-for="version in model_versions"
                    :key="version.version"
                    class="flex items-center justify-between gap-3 px-4 py-3"
                >
                    <span class="font-mono text-sm text-ink-200">{{ version.version }}</span>
                    <span class="flex items-center gap-4">
                        <span class="text-xs text-ink-500">{{ version.total }} settled</span>
                        <span class="w-16 text-right font-mono text-sm font-semibold text-ink-100">
                            {{ pct(version.hit_rate) }}
                        </span>
                    </span>
                </li>
            </ul>
        </section>

        <!-- Accumulators that have run -->
        <section v-if="accumulators.length" class="mb-7">
            <SectionHeading
                title="Accumulators that have run"
                hint="Tickets leave the accumulators page once their last match kicks off. Results are scored overnight."
            />
            <div class="space-y-3.5">
                <div v-for="ticket in accumulators" :key="ticket.id">
                    <p class="mb-1.5 text-[11px] font-bold uppercase tracking-[.14em] text-ink-500">
                        {{ ticketName(ticket) }}
                    </p>
                    <AccaTicket :ticket="ticket" finished />
                </div>
            </div>
        </section>
    </AppLayout>
</template>
