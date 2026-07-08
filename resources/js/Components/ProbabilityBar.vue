<script setup>
defineProps({
    probability: {
        type: Number,
        required: true,
    },
});
</script>

<template>
    <div class="flex items-center gap-2">
        <div
            class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-800"
            role="meter"
            :aria-valuenow="Math.round(probability * 100)"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div
                class="h-full rounded-full bg-green-500"
                :style="{ width: `${Math.round(probability * 100)}%` }"
            />
        </div>
        <span class="w-10 text-right font-mono text-xs font-semibold text-slate-200">
            {{ Math.round(probability * 100) }}%
        </span>
    </div>
</template>
