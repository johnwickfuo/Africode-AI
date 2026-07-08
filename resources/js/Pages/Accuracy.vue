<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import { marketLabel } from '../lib/markets';

const props = defineProps({
    windows: { type: Object, required: true },
    model_versions: { type: Array, default: () => [] },
});

const windowOptions = [
    { key: '30', label: 'Last 30 days' },
    { key: '90', label: 'Last 90 days' },
    { key: 'all', label: 'All time' },
];

const activeWindow = ref('30');
const stats = computed(() => props.windows[activeWindow.value]);

const pct = (value) => (value === null || value === undefined ? '—' : `${(value * 100).toFixed(1)}%`);
const gapClass = (gap) => {
    if (gap === null || gap === undefined) return 'text-slate-400';
    return Math.abs(gap) <= 0.05 ? 'text-green-400' : gap > 0 ? 'text-rose-400' : 'text-amber-400';
};
</script>

<template>
    <Head title="Accuracy" />

    <AppLayout>
        <h1 class="mb-1 text-xl font-bold">Accuracy tracker</h1>
        <p class="mb-5 text-sm text-slate-400">
            How the models actually perform, settled pick by settled pick.
        </p>

        <nav class="mb-6 flex gap-2" aria-label="Time window">
            <button
                v-for="option in windowOptions"
                :key="option.key"
                type="button"
                class="rounded-full px-3 py-1.5 text-sm font-semibold transition"
                :class="activeWindow === option.key
                    ? 'bg-green-500 text-slate-950'
                    : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                @click="activeWindow = option.key"
            >
                {{ option.label }}
            </button>
        </nav>

        <template v-if="stats.total_settled > 0">
            <!-- Best bet headline stats -->
            <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl border border-green-500/40 bg-green-500/5 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-green-400">Best Bet win rate</p>
                    <p class="mt-1 text-2xl font-bold">{{ pct(stats.best_bets.hit_rate) }}</p>
                    <p class="text-xs text-slate-400">{{ stats.best_bets.hits }}/{{ stats.best_bets.total }} won</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Best Bet avg prob</p>
                    <p class="mt-1 text-2xl font-bold">{{ pct(stats.best_bets.avg_probability) }}</p>
                    <p class="text-xs text-slate-400">what the model claimed</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Settled picks</p>
                    <p class="mt-1 text-2xl font-bold">{{ stats.total_settled }}</p>
                    <p class="text-xs text-slate-400">across all markets</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Markets tracked</p>
                    <p class="mt-1 text-2xl font-bold">{{ stats.markets.length }}</p>
                    <p class="text-xs text-slate-400">with settled picks</p>
                </div>
            </div>

            <!-- Per-market hit rates -->
            <section class="mb-6">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-widest text-slate-400">
                    Hit rate by market
                </h2>
                <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900">
                    <table class="w-full min-w-[26rem] text-sm">
                        <thead>
                            <tr class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                                <th class="px-4 py-2.5 text-left font-semibold">Market</th>
                                <th class="px-3 py-2.5 text-right font-semibold">Settled</th>
                                <th class="px-3 py-2.5 text-right font-semibold">Won</th>
                                <th class="px-3 py-2.5 text-right font-semibold">Hit rate</th>
                                <th class="px-4 py-2.5 text-right font-semibold">Avg prob</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="market in stats.markets"
                                :key="market.market"
                                class="border-b border-slate-800/60 last:border-b-0"
                            >
                                <td class="px-4 py-2 text-slate-200">{{ marketLabel(market.market) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-slate-300">{{ market.total }}</td>
                                <td class="px-3 py-2 text-right font-mono text-slate-300">{{ market.hits }}</td>
                                <td class="px-3 py-2 text-right font-mono font-semibold text-slate-100">
                                    {{ pct(market.hit_rate) }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-slate-400">
                                    {{ pct(market.avg_probability) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Calibration -->
            <section class="mb-6">
                <h2 class="mb-1 text-sm font-semibold uppercase tracking-widest text-slate-400">
                    Calibration
                </h2>
                <p class="mb-3 text-xs text-slate-500">
                    Within each predicted-probability bucket, did picks actually win that often?
                    Gap = claimed − actual: positive means overconfident.
                </p>
                <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900">
                    <table class="w-full min-w-[21rem] text-sm">
                        <thead>
                            <tr class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                                <th class="px-3 py-2.5 text-left font-semibold">Predicted</th>
                                <th class="px-2 py-2.5 text-right font-semibold">Picks</th>
                                <th class="px-2 py-2.5 text-right font-semibold">Actual</th>
                                <th class="px-3 py-2.5 text-right font-semibold">Gap</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="bucket in stats.calibration"
                                :key="bucket.bucket"
                                class="border-b border-slate-800/60 last:border-b-0"
                            >
                                <td class="px-3 py-2 font-mono text-slate-200">{{ bucket.bucket }}</td>
                                <td class="px-2 py-2 text-right font-mono text-slate-300">{{ bucket.total }}</td>
                                <td class="px-2 py-2 text-right font-mono text-slate-100">{{ pct(bucket.hit_rate) }}</td>
                                <td class="px-3 py-2 text-right font-mono font-semibold" :class="gapClass(bucket.gap)">
                                    {{ bucket.gap > 0 ? '+' : '' }}{{ (bucket.gap * 100).toFixed(1) }}pp
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <div
            v-else
            class="mb-6 rounded-xl border border-dashed border-slate-700 p-8 text-center text-slate-400"
        >
            <p class="font-semibold">No settled predictions in this window yet.</p>
            <p class="mt-2 text-sm">
                Accuracy appears after predictions settle against finished matches
                (nightly settlement job).
            </p>
        </div>

        <!-- Model versions -->
        <section v-if="model_versions.length" class="mb-6">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-widest text-slate-400">
                Model versions (all time)
            </h2>
            <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900">
                <table class="w-full min-w-[20rem] text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-4 py-2.5 text-left font-semibold">Version</th>
                            <th class="px-3 py-2.5 text-right font-semibold">Settled</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Hit rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="version in model_versions"
                            :key="version.version"
                            class="border-b border-slate-800/60 last:border-b-0"
                        >
                            <td class="px-4 py-2 font-mono text-slate-200">{{ version.version }}</td>
                            <td class="px-3 py-2 text-right font-mono text-slate-300">{{ version.total }}</td>
                            <td class="px-4 py-2 text-right font-mono font-semibold text-slate-100">
                                {{ pct(version.hit_rate) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
