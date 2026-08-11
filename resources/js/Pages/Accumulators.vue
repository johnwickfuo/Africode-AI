<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import AccaTicket from '../Components/AccaTicket.vue';
import EmptyState from '../Components/EmptyState.vue';
import PageHeader from '../Components/PageHeader.vue';
import SectionHeading from '../Components/SectionHeading.vue';

const props = defineProps({
    generated_at: { type: String, default: null },
    families: { type: Array, default: () => [] },
    max_legs: { type: Number, default: 25 },
});

const activeKey = ref(props.families[0]?.key ?? null);
const active = computed(
    () => props.families.find((family) => family.key === activeKey.value) ?? props.families[0],
);

const anyAvailable = computed(() =>
    (active.value?.groups ?? []).some((group) => group.tickets.some((ticket) => ticket.available)),
);

const builtCount = (family) =>
    family.groups.reduce(
        (total, group) => total + group.tickets.filter((ticket) => ticket.available).length,
        0,
    );

const capLabel = (row) => (row.max_leg_odds ? `${row.max_leg_odds.toFixed(2)} · ` : '');
</script>

<template>
    <Head title="Accumulators" />

    <AppLayout>
        <PageHeader
            eyebrow="Checked every hour"
            title="Accumulators"
            subtitle="Model picks combined into tickets by target odds. Each ticket runs over at most two consecutive days, and steps aside for a fresh one once a third of it has kicked off — so everything here is still backable in full."
        >
            <template #actions>
                <span v-if="generated_at" class="text-xs text-ink-500">
                    Generated {{ generated_at }}
                </span>
            </template>
        </PageHeader>

        <!-- Family tabs -->
        <div class="mb-4 flex gap-2" role="tablist">
            <button
                v-for="family in families"
                :key="family.key"
                type="button"
                role="tab"
                :aria-selected="family.key === activeKey"
                class="flex-1 rounded-xl border px-4 py-2.5 text-sm font-bold transition"
                :class="
                    family.key === activeKey
                        ? 'border-brand-500/50 bg-brand-500/15 text-brand-300'
                        : 'border-ink-800 bg-ink-900/40 text-ink-400 hover:text-ink-200'
                "
                @click="activeKey = family.key"
            >
                {{ family.label }}
                <span class="ml-1 font-mono text-xs opacity-70">{{ builtCount(family) }}</span>
            </button>
        </div>

        <p v-if="active" class="mb-5 text-sm leading-relaxed text-ink-400">
            {{ active.blurb }}
        </p>

        <template v-if="anyAvailable">
            <section v-for="(group, index) in active.groups" :key="index" :class="index ? 'mt-7' : ''">
                <SectionHeading v-if="group.label" :title="group.label" :hint="group.hint" />
                <div class="space-y-3.5">
                    <AccaTicket
                        v-for="ticket in group.tickets"
                        :key="`${ticket.max_leg_odds ?? 'any'}-${ticket.target}`"
                        :ticket="ticket"
                    />
                </div>
            </section>
        </template>

        <EmptyState
            v-else
            icon="ticket"
            title="No tickets in this set today"
            message="Tickets are assembled every morning from the day's predictions. Banker tickets need a lot of short calls at once, so they mostly appear on busy weekends."
        />

        <!-- Record -->
        <section v-if="active?.record.length" class="mt-8">
            <SectionHeading title="Record" hint="All settled tickets in this set since launch." />
            <ul class="card divide-y divide-ink-800/70">
                <li
                    v-for="row in active.record"
                    :key="`${row.max_leg_odds ?? 'any'}-${row.target}`"
                    class="flex items-center justify-between gap-3 px-4 py-3"
                >
                    <span class="font-mono text-sm font-bold text-ink-100">
                        {{ capLabel(row) }}{{ row.target }}x
                    </span>
                    <span class="flex items-center gap-4 text-sm">
                        <span class="font-mono font-semibold text-brand-400">{{ row.won }} won</span>
                        <span class="font-mono text-ink-500">of {{ row.total }}</span>
                    </span>
                </li>
            </ul>
        </section>

        <p class="mt-6 text-xs leading-relaxed text-ink-500">
            Odds shown are what a mainstream bookmaker would be expected to pay, not the
            model's own fair price. Match result and the 2.5-goals line use real published
            prices; everything else — corners, cards, team goals, other goal lines — is
            estimated from the model's probability and the going margin on that market, because
            no free source prices them. Your bookmaker will still differ, so check the slip
            before staking. Tickets carry at most {{ max_legs }} legs. Long accumulators are
            entertainment, not investment — a 10,000x ticket wins roughly once in ten thousand
            attempts by construction.
        </p>
    </AppLayout>
</template>
