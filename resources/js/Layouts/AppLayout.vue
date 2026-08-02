<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ChatWidget from '../Components/ChatWidget.vue';

const page = usePage();

// Icons are inline paths so the app ships no icon dependency.
const navigation = [
    {
        label: 'Fixtures',
        href: '/',
        icon: 'M7 3v3M17 3v3M4 9h16M5 6h14a1 1 0 011 1v12a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1z',
    },
    {
        label: 'Accas',
        href: '/accumulators',
        icon: 'M4 8a2 2 0 012-2h12a2 2 0 012 2v1a2 2 0 000 4v1a2 2 0 01-2 2H6a2 2 0 01-2-2v-1a2 2 0 000-4V8z',
    },
    {
        label: 'Accuracy',
        href: '/accuracy',
        icon: 'M4 19h16M7 16V9M12 16V5M17 16v-4',
    },
    {
        label: 'History',
        href: '/history',
        icon: 'M12 8v4l3 2M3.05 11a9 9 0 1 1 .5 4',
    },
];

const isActive = (href) => (href === '/' ? page.url === '/' : page.url.startsWith(href));

const pipeline = computed(() => page.props.pipeline ?? {});

const freshness = computed(() => [
    { label: 'Fixtures', at: pipeline.value.fixtures_as_of },
    { label: 'Stats', at: pipeline.value.stats_as_of },
    { label: 'Predictions', at: pipeline.value.predictions_as_of },
]);
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <!-- Top bar -->
        <header class="sticky top-0 z-30 border-b border-ink-800/80 bg-ink-950/85 backdrop-blur-xl">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <Link href="/" class="group flex min-w-0 items-center gap-2.5">
                    <span
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-gradient text-base font-black text-ink-950 shadow-glow-sm transition group-hover:scale-105"
                        aria-hidden="true"
                    >
                        A
                    </span>
                    <span class="min-w-0 leading-tight">
                        <span class="block truncate text-[15px] font-bold tracking-tight text-ink-100">
                            Africode <span class="text-brand-400">Football AI</span>
                        </span>
                        <span class="hidden text-[11px] text-ink-500 sm:block">
                            Model-driven predictions · 12 European leagues
                        </span>
                    </span>
                </Link>

                <nav class="hidden shrink-0 items-center gap-1 md:flex" aria-label="Main">
                    <Link
                        v-for="item in navigation"
                        :key="item.href"
                        :href="item.href"
                        class="rounded-xl px-3.5 py-2 text-sm font-semibold transition"
                        :class="isActive(item.href)
                            ? 'bg-ink-800 text-brand-300 shadow-inner'
                            : 'text-ink-400 hover:bg-ink-850 hover:text-ink-100'"
                        :aria-current="isActive(item.href) ? 'page' : undefined"
                    >
                        {{ item.label }}
                    </Link>
                </nav>
            </div>
        </header>

        <!-- Page -->
        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6 sm:py-8">
            <slot />
        </main>

        <!-- Footer -->
        <footer class="mt-8 border-t border-ink-800/80 bg-ink-950/60">
            <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        v-for="item in freshness"
                        :key="item.label"
                        class="inline-flex items-center gap-1.5 rounded-full border border-ink-800 bg-ink-900 px-2.5 py-1 text-[11px] text-ink-400"
                    >
                        <span
                            class="h-1.5 w-1.5 rounded-full"
                            :class="item.at ? 'bg-brand-400' : 'bg-ink-600'"
                        />
                        {{ item.label }}
                        <span class="font-medium text-ink-300">{{ item.at ?? 'pending' }}</span>
                    </span>
                </div>

                <div class="mt-5 flex flex-col gap-3 border-t border-ink-800/70 pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs leading-relaxed text-ink-500">
                        <span class="font-semibold text-ink-300">Africode Football AI</span> —
                        statistical models, not certainties. All times Africa/Lagos.
                    </p>
                    <p class="text-xs font-medium text-ink-500">
                        18+ · Predictions are for information only. Bet responsibly.
                    </p>
                </div>
            </div>
            <div class="h-20 md:h-0" aria-hidden="true" />
        </footer>

        <!-- Mobile tab bar -->
        <nav
            class="fixed inset-x-0 bottom-0 z-30 border-t border-ink-800 bg-ink-950/95 pb-safe backdrop-blur-xl md:hidden"
            aria-label="Main"
        >
            <div class="mx-auto grid max-w-md grid-cols-4">
                <Link
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-semibold transition"
                    :class="isActive(item.href) ? 'text-brand-400' : 'text-ink-500'"
                    :aria-current="isActive(item.href) ? 'page' : undefined"
                >
                    <span
                        class="flex h-8 w-14 items-center justify-center rounded-lg transition"
                        :class="isActive(item.href) ? 'bg-brand-500/12' : ''"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                            <path :d="item.icon" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    {{ item.label }}
                </Link>
            </div>
        </nav>

        <ChatWidget />
    </div>
</template>
