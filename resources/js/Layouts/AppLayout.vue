<script setup>
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();

const navigation = [
    { label: 'Fixtures', href: '/' },
    { label: 'Accuracy', href: '/accuracy' },
    { label: 'History', href: '/history' },
];

const isActive = (href) =>
    href === '/' ? page.url === '/' : page.url.startsWith(href);
</script>

<template>
    <div class="mx-auto flex min-h-screen max-w-5xl flex-col px-4">
        <header class="sticky top-0 z-10 -mx-4 border-b border-slate-800 bg-slate-950/95 px-4 py-3 backdrop-blur">
            <div class="flex items-center justify-between gap-3">
                <Link href="/" class="flex min-w-0 items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-green-500 text-sm font-black text-slate-950">
                        A
                    </span>
                    <span class="truncate whitespace-nowrap text-sm font-bold tracking-tight sm:text-lg">
                        Africode <span class="text-green-400">Football AI</span>
                    </span>
                </Link>
                <nav class="flex shrink-0 gap-0.5 sm:gap-1" aria-label="Main">
                    <Link
                        v-for="item in navigation"
                        :key="item.href"
                        :href="item.href"
                        class="rounded-lg px-2 py-1.5 text-xs font-semibold transition sm:px-3 sm:text-sm"
                        :class="isActive(item.href)
                            ? 'bg-slate-800 text-green-400'
                            : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200'"
                    >
                        {{ item.label }}
                    </Link>
                </nav>
            </div>
        </header>

        <main class="flex-1 py-6">
            <slot />
        </main>

        <footer class="mt-6 border-t border-slate-800 py-4 text-xs leading-relaxed text-slate-500">
            <p v-if="$page.props.pipeline">
                <template v-if="$page.props.pipeline.fixtures_as_of">
                    Fixtures as of {{ $page.props.pipeline.fixtures_as_of }}
                </template>
                <template v-else>Fixtures not synced yet</template>
                ·
                <template v-if="$page.props.pipeline.stats_as_of">
                    stats as of {{ $page.props.pipeline.stats_as_of }}
                </template>
                <template v-else>stats not imported yet</template>
                ·
                <template v-if="$page.props.pipeline.predictions_as_of">
                    predictions as of {{ $page.props.pipeline.predictions_as_of }}
                </template>
                <template v-else>no predictions generated yet</template>
                (Africa/Lagos)
            </p>
            <p class="mt-1">Africode Football AI — statistical models, not certainties. Bet responsibly.</p>
        </footer>
    </div>
</template>
