<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import EmptyState from '../Components/EmptyState.vue';
import OutcomeBadge from '../Components/OutcomeBadge.vue';
import PageHeader from '../Components/PageHeader.vue';
import SectionHeading from '../Components/SectionHeading.vue';
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
        <PageHeader
            eyebrow="Built fresh each morning"
            title="Accumulators"
            subtitle="Model picks combined into tickets by target odds. No two tickets share a call on the same market, so a single result can never sink the whole set."
        >
            <template #actions>
                <span v-if="generated_at" class="text-xs text-ink-500">
                    Generated {{ generated_at }}
                </span>
            </template>
        </PageHeader>

        <div v-if="tiers.some((tier) => tier.available)" class="space-y-3.5">
            <article
                v-for="tier in tiers"
                :key="tier.target"
                class="card animate-fade-up"
                :class="tier.available ? '' : 'opacity-70'"
            >
                <!-- Ticket header -->
                <div class="flex flex-wrap items-center gap-3 border-b border-dashed border-ink-700/70 p-4">
                    <span class="rounded-xl bg-brand-gradient px-3 py-1.5 text-base font-black tracking-tight text-ink-950 shadow-glow-sm">
                        {{ tier.target }}x
                    </span>

                    <template v-if="tier.available">
                        <div class="min-w-0">
                            <p class="font-mono text-sm font-bold text-brand-300">
                                {{ tier.combined_odds.toFixed(2) }} odds
                            </p>
                            <p class="text-xs text-ink-500">
                                {{ tier.legs.length }} legs · {{ pct(tier.combined_probability) }} win chance
                            </p>
                        </div>
                        <OutcomeBadge
                            v-if="tier.outcome !== 'pending'"
                            :outcome="tier.outcome"
                            class="ml-auto"
                        />
                    </template>
                    <p v-else class="min-w-0 text-sm text-ink-500">Not available today</p>
                </div>

                <!-- Legs -->
                <ul v-if="tier.available" class="divide-y divide-ink-800/60">
                    <li
                        v-for="leg in tier.legs"
                        :key="`${leg.fixture_id}-${leg.market}`"
                        class="flex items-center justify-between gap-3 px-4 py-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-ink-100">
                                {{ marketLabel(leg.market) }}: {{ lineLabel(leg) }}
                            </p>
                            <Link
                                :href="`/match/${leg.fixture_id}`"
                                class="mt-0.5 block truncate text-xs text-ink-500 transition hover:text-ink-300"
                            >
                                {{ leg.match }} · {{ leg.kickoff }}
                            </Link>
                        </div>
                        <span class="shrink-0 font-mono text-sm font-semibold text-ink-300">
                            {{ leg.odds.toFixed(2) }}
                        </span>
                    </li>
                </ul>
                <p v-else class="px-4 py-4 text-sm leading-relaxed text-ink-500">
                    Not enough independent picks to reach {{ tier.target }}x right now — this
                    ticket returns once more fixtures are predicted.
                </p>
            </article>
        </div>

        <EmptyState
            v-else
            icon="ticket"
            title="No accumulators today"
            message="Tickets are assembled every morning from the day's predictions. Once fixtures are inside the seven-day window, they appear here automatically."
        />

        <!-- Record -->
        <section v-if="record.length" class="mt-8">
            <SectionHeading title="Record by tier" hint="All settled tickets since launch." />
            <ul class="card divide-y divide-ink-800/70">
                <li
                    v-for="row in record"
                    :key="row.target"
                    class="flex items-center justify-between gap-3 px-4 py-3"
                >
                    <span class="font-mono text-sm font-bold text-ink-100">{{ row.target }}x</span>
                    <span class="flex items-center gap-4 text-sm">
                        <span class="font-mono font-semibold text-brand-400">{{ row.won }} won</span>
                        <span class="font-mono text-ink-500">of {{ row.total }}</span>
                    </span>
                </li>
            </ul>
        </section>

        <p class="mt-6 text-xs leading-relaxed text-ink-500">
            Odds shown are the model's own fair odds (1 ÷ probability); bookmaker prices will
            differ. Long accumulators are entertainment, not investment — a 10,000x ticket wins
            roughly once in ten thousand attempts by construction.
        </p>
    </AppLayout>
</template>
