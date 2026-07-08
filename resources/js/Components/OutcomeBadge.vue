<script setup>
defineProps({
    outcome: {
        type: String,
        required: true,
    },
});

const styles = {
    won: 'bg-green-500/15 text-green-400',
    lost: 'bg-rose-500/15 text-rose-400',
    void: 'bg-slate-700/40 text-slate-400',
    pending: 'bg-slate-700/40 text-slate-400',
};
</script>

<template>
    <span
        class="rounded px-2 py-0.5 text-xs font-bold uppercase tracking-wide"
        :class="styles[outcome] ?? styles.pending"
    >
        {{ outcome }}
    </span>
</template>
