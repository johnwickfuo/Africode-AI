<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import OutcomeBadge from './OutcomeBadge.vue';
import { lineLabel, marketLabel } from '../lib/markets';

const props = defineProps({
    ticket: { type: Object, required: true },
});

// Banker tickets run to 20+ legs; showing them all by default would bury
// every other ticket on a phone.
const COLLAPSE_AFTER = 6;

const expanded = ref(false);
const legs = computed(() => props.ticket.legs ?? []);
const collapsible = computed(() => legs.value.length > COLLAPSE_AFTER);
const visibleLegs = computed(() =>
    collapsible.value && !expanded.value ? legs.value.slice(0, COLLAPSE_AFTER) : legs.value,
);

const pct = (probability) => {
    const value = probability * 100;
    return value >= 1 ? `${value.toFixed(1)}%` : `${value.toFixed(3)}%`;
};
</script>

<template>
    <article class="card animate-fade-up" :class="ticket.available ? '' : 'opacity-70'">
        <!-- Ticket header -->
        <div class="flex flex-wrap items-center gap-3 border-b border-dashed border-ink-700/70 p-4">
            <span class="rounded-xl bg-brand-gradient px-3 py-1.5 text-base font-black tracking-tight text-ink-950 shadow-glow-sm">
                {{ ticket.target }}x
            </span>

            <template v-if="ticket.available">
                <div class="min-w-0">
                    <p class="font-mono text-sm font-bold text-brand-300">
                        {{ ticket.combined_odds.toFixed(2) }} odds
                    </p>
                    <p class="text-xs text-ink-500">
                        {{ legs.length }} legs · {{ pct(ticket.combined_probability) }} win chance
                    </p>
                    <p v-if="ticket.window" class="mt-0.5 text-xs font-semibold text-ink-400">
                        {{ ticket.window }}
                    </p>
                </div>
                <OutcomeBadge
                    v-if="ticket.outcome !== 'pending'"
                    :outcome="ticket.outcome"
                    class="ml-auto"
                />
            </template>
            <p v-else class="min-w-0 text-sm text-ink-500">Not available today</p>
        </div>

        <!-- Legs -->
        <template v-if="ticket.available">
            <ul class="divide-y divide-ink-800/60">
                <li
                    v-for="leg in visibleLegs"
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

            <button
                v-if="collapsible"
                type="button"
                class="w-full border-t border-ink-800/60 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-ink-400 transition hover:bg-ink-800/40 hover:text-ink-200"
                @click="expanded = !expanded"
            >
                {{ expanded ? 'Show fewer' : `Show all ${legs.length} legs` }}
            </button>
        </template>

        <p v-else class="px-4 py-4 text-sm leading-relaxed text-ink-500">
            Not enough independent picks to reach {{ ticket.target }}x
            <template v-if="ticket.max_leg_odds">
                using legs no longer than {{ ticket.max_leg_odds.toFixed(2) }}
            </template>
            right now — this ticket returns once more fixtures are predicted.
        </p>
    </article>
</template>
