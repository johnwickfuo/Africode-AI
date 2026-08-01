<script setup>
import { computed } from 'vue';

const props = defineProps({
    probability: { type: Number, required: true },
    // 'sm' for dense lists, 'md' for feature rows.
    size: { type: String, default: 'sm' },
    // Colour the fill by confidence unless a class is passed explicitly.
    tone: { type: String, default: 'auto' },
});

const percent = computed(() => Math.round(props.probability * 100));

const fill = computed(() => {
    if (props.tone !== 'auto') return props.tone;
    if (props.probability >= 0.75) return 'bg-brand-gradient';
    if (props.probability >= 0.6) return 'bg-brand-500';
    return 'bg-ink-500';
});
</script>

<template>
    <div class="flex items-center gap-2.5">
        <div
            class="flex-1 overflow-hidden rounded-full bg-ink-800"
            :class="size === 'md' ? 'h-2' : 'h-1.5'"
            role="meter"
            :aria-valuenow="percent"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div
                class="h-full origin-left animate-bar-grow rounded-full"
                :class="fill"
                :style="{ width: `${percent}%` }"
            />
        </div>
        <span
            class="shrink-0 text-right font-mono font-semibold text-ink-100"
            :class="size === 'md' ? 'w-11 text-sm' : 'w-9 text-xs'"
        >
            {{ percent }}%
        </span>
    </div>
</template>
