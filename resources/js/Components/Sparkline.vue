<script setup>
import { computed } from 'vue';

const props = defineProps({
    values: {
        type: Array,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
});

const WIDTH = 120;
const HEIGHT = 32;
const PAD = 3;

const points = computed(() => {
    if (props.values.length < 2) return '';
    const max = Math.max(...props.values, 0.1);
    const step = (WIDTH - PAD * 2) / (props.values.length - 1);
    return props.values
        .map((v, i) => `${PAD + i * step},${HEIGHT - PAD - (v / max) * (HEIGHT - PAD * 2)}`)
        .join(' ');
});

const lastPoint = computed(() => {
    const parts = points.value.split(' ');
    return parts.length ? parts[parts.length - 1].split(',') : null;
});
</script>

<template>
    <div>
        <svg
            v-if="values.length >= 2"
            :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
            class="h-8 w-full max-w-[8rem]"
            role="img"
            :aria-label="`${label}: xG last ${values.length} matches: ${values.join(', ')}`"
        >
            <polyline
                :points="points"
                fill="none"
                stroke="#22c55e"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <circle v-if="lastPoint" :cx="lastPoint[0]" :cy="lastPoint[1]" r="2.5" fill="#22c55e" />
        </svg>
        <p v-else class="text-xs text-slate-500">Not enough data</p>
    </div>
</template>
