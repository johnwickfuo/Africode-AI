<script setup>
defineProps({
    eyebrow: { type: String, default: null },
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
});
</script>

<template>
    <header class="mb-6 animate-fade-up">
        <p v-if="eyebrow" class="label mb-1.5 !text-brand-400">{{ eyebrow }}</p>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h1 class="text-2xl font-bold tracking-tight text-ink-100 sm:text-3xl">{{ title }}</h1>
            <slot name="actions" />
        </div>
        <p v-if="subtitle" class="mt-2 max-w-2xl text-sm leading-relaxed text-ink-400">
            {{ subtitle }}
        </p>
    </header>
</template>
