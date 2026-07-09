<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import OutcomeBadge from '../Components/OutcomeBadge.vue';
import { lineLabel, marketLabel } from '../lib/markets';

defineProps({
    generated_at: { type: String, default: null },
    tiers: { type: Array, default: () => [] },
    record: { type: Array, default: () => [] },
});

const pct = (probability) => {
    const value = probability * 100;
    return value >= 1 ? `${value.toFixed(1)}%` : `${value.toFixed(3)}%`;
};
</script>

<template>
    <Head title="Accumulators" />

    <AppLayout>
        <h1 class="mb-1 text-xl font-bold">Accumulators</h1>
        <p class="mb-5 text-sm text-slate-400">
            Model picks combined into tickets by target odds — fair odds from the
            model's own probabilities. No two tickets share a call on the same market.
        </p>

        <p v-if="generated_at" class="mb-5 text-xs text-slate-500">
            Latest set generated {{ generated_at }} (Africa/Lagos).
        </p>

        <div v-if="tiers.some((tier) => tier.available)" class="space-y-4">
            <div
                v-for="tier in tiers"
                :key="tier.target"
                class="rounded-xl border bg-slate-900 p-4"
                :class="tier.available ? 'border-slate-800' : 'border-dashed border-slate-700'"
            >
                <div class="mb-3 flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="rounded-lg bg-green-500 px-2.5 py-1 text-sm font-black text-slate-950">
                            {{ tier.target }}x
                        </span>
                        <template v-if="tier.available">
                            <span class="font-mono text-sm font-bold text-green-400">
                                {{ tier.combined_odds.toFixed(2) }} odds
                            </span>
                            <span class="text-xs text-slate-400">
                                · {{ tier.legs.length }} legs · {{ pct(tier.combined_probability) }} win chance
                            </span>
                        </template>
                    </div>
                    <OutcomeBadge v-if="tier.available && tier.outcome !== 'pending'" :outcome="tier.outcome" />
                </div>

                <template v-if="tier.available">
                    <ul class="divide-y divide-slate-800/60 overflow-hidden rounded-lg bg-slate-950/50">
                        <li
                            v-for="leg in tier.legs"
                            :key="`${leg.fixture_id}-${leg.market}`"
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-slate-200">
                                    {{ marketLabel(leg.market) }}: {{ lineLabel(leg) }}
                                </p>
                                <Link
                                    :href="`/match/${leg.fixture_id}`"
                                    class="text-xs text-slate-400 hover:text-slate-200"
                                >
                                    {{ leg.match }} · {{ leg.kickoff }}
                                </Link>
                            </div>
                            <span class="shrink-0 font-mono text-xs font-semibold text-slate-300">
                                {{ leg.odds.toFixed(2) }}
                            </span>
                        </li>
                    </ul>
                </template>
                <p v-else class="text-sm text-slate-500">
                    Not enough separate predictions to reach {{ tier.target }}x right now —
                    this tier returns when more fixtures are predicted.
                </p>
            </div>
        </div>

        <div
            v-else
            class="rounded-xl border border-dashed border-slate-700 p-8 text-center text-slate-400"
        >
            <p class="font-semibold">No accumulators yet.</p>
            <p class="mt-2 text-sm">
                Accas are built daily at 06:30 from the day's predictions. Run
                <code class="rounded bg-slate-800 px-1.5 py-0.5 text-green-400">php artisan africode:generate-accas --now</code>
                to build a set now.
            </p>
        </div>

        <!-- Record -->
        <section v-if="record.length" class="mt-8">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-widest text-slate-400">
                Record by tier (all time)
            </h2>
            <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900">
                <table class="w-full min-w-[16rem] text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-4 py-2.5 text-left font-semibold">Tier</th>
                            <th class="px-3 py-2.5 text-right font-semibold">Won</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Settled</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in record"
                            :key="row.target"
                            class="border-b border-slate-800/60 last:border-b-0"
                        >
                            <td class="px-4 py-2 font-mono font-bold text-slate-200">{{ row.target }}x</td>
                            <td class="px-3 py-2 text-right font-mono text-green-400">{{ row.won }}</td>
                            <td class="px-4 py-2 text-right font-mono text-slate-300">{{ row.total }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="mt-6 text-xs text-slate-500">
            Odds are the model's fair odds (1 ÷ probability) — bookmaker prices will differ.
            Long accumulators are entertainment, not investment: a {{ 10000 }}x ticket wins
            about once in ten thousand tries by construction.
        </p>
    </AppLayout>
</template>
