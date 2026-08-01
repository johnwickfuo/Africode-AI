<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    hint: { type: String, default: null },
    // 'brand' highlights the headline metric on a page.
    tone: { type: String, default: 'default' },
});
</script>

<template>
    <div
        class="card p-4"
        :class="tone === 'brand' ? '!border-brand-500/35 !bg-brand-500/[.07]' : ''"
    >
        <p class="label" :class="tone === 'brand' ? '!text-brand-300' : ''">{{ label }}</p>
        <p class="mt-1.5 text-2xl font-bold tracking-tight text-ink-100" data-nums>{{ value }}</p>
        <p v-if="hint" class="mt-0.5 text-xs text-ink-400">{{ hint }}</p>
    </div>
</template>
