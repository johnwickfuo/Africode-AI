<script setup>
defineProps({
    title: { type: String, required: true },
    hint: { type: String, default: null },
});
</script>

<template>
    <div class="mb-3">
        <div class="flex items-baseline justify-between gap-3">
            <h2 class="text-sm font-bold uppercase tracking-[.14em] text-ink-300">{{ title }}</h2>
            <slot name="aside" />
        </div>
        <p v-if="hint" class="mt-1 text-xs leading-relaxed text-ink-500">{{ hint }}</p>
    </div>
</template>
