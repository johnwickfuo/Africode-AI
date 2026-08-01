<script setup>
defineProps({
    title: { type: String, required: true },
    message: { type: String, default: null },
    icon: { type: String, default: 'calendar' },
});

// Inline paths keep the bundle free of an icon dependency.
const paths = {
    calendar: 'M7 3v3M17 3v3M4 9h16M5 6h14a1 1 0 011 1v12a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1z',
    chart: 'M4 19h16M7 16V9M12 16V5M17 16v-4',
    ticket: 'M4 8a2 2 0 012-2h12a2 2 0 012 2v1a2 2 0 000 4v1a2 2 0 01-2 2H6a2 2 0 01-2-2v-1a2 2 0 000-4V8z',
};
</script>

<template>
    <div class="card px-6 py-12 text-center">
        <span
            class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-ink-800 text-ink-400"
            aria-hidden="true"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-6 w-6">
                <path :d="paths[icon] ?? paths.calendar" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
        <p class="text-base font-semibold text-ink-100">{{ title }}</p>
        <p v-if="message" class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-ink-400">
            {{ message }}
        </p>
        <slot />
    </div>
</template>
