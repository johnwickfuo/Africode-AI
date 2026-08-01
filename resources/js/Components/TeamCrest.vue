<script setup>
import { computed } from 'vue';

const props = defineProps({
    team: { type: Object, required: true },
    size: { type: String, default: 'md' },
});

// Initials stand in wherever a club has no crest on file.
const initials = computed(() => {
    const source = props.team.short_name || props.team.name || '';
    return source.replace(/[^A-Za-z ]/g, '').slice(0, 3).toUpperCase();
});

const box = computed(() => (props.size === 'lg' ? 'h-12 w-12 text-sm' : 'h-9 w-9 text-[11px]'));
</script>

<template>
    <span
        class="flex shrink-0 items-center justify-center overflow-hidden rounded-xl border border-ink-700/70 bg-ink-800 font-bold tracking-tight text-ink-300"
        :class="box"
    >
        <img
            v-if="team.logo_url"
            :src="team.logo_url"
            :alt="team.name"
            class="h-full w-full object-contain p-1"
            loading="lazy"
        />
        <span v-else aria-hidden="true">{{ initials }}</span>
    </span>
</template>
