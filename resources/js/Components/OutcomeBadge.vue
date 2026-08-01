<script setup>
defineProps({
    outcome: { type: String, required: true },
});

const styles = {
    won: 'bg-brand-500/15 text-brand-300 ring-1 ring-inset ring-brand-500/30',
    lost: 'bg-rose-500/15 text-rose-300 ring-1 ring-inset ring-rose-500/25',
    void: 'bg-ink-700/50 text-ink-300 ring-1 ring-inset ring-ink-600/50',
    pending: 'bg-ink-700/50 text-ink-300 ring-1 ring-inset ring-ink-600/50',
};

const labels = { won: 'Won', lost: 'Lost', void: 'Void', pending: 'Pending' };
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-bold uppercase tracking-wide"
        :class="styles[outcome] ?? styles.pending"
    >
        <span
            class="h-1.5 w-1.5 rounded-full"
            :class="{
                'bg-brand-400': outcome === 'won',
                'bg-rose-400': outcome === 'lost',
                'bg-ink-400': outcome !== 'won' && outcome !== 'lost',
            }"
        />
        {{ labels[outcome] ?? outcome }}
    </span>
</template>
