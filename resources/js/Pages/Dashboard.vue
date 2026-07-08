<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    leagues: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <Head title="Fixtures" />

    <div class="mx-auto flex min-h-screen max-w-5xl flex-col px-4 py-6">
        <header class="mb-8 flex items-center gap-3">
            <span
                class="flex h-9 w-9 items-center justify-center rounded-lg bg-green-500 font-black text-slate-950"
            >
                A
            </span>
            <h1 class="text-xl font-bold tracking-tight">
                Africode <span class="text-green-400">Football AI</span>
            </h1>
        </header>

        <main class="flex-1">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-widest text-slate-400">
                Tracked leagues
            </h2>
            <ul class="grid gap-3 sm:grid-cols-2">
                <li
                    v-for="league in leagues"
                    :key="league.id"
                    class="rounded-xl border border-slate-800 bg-slate-900 p-4"
                >
                    <div class="flex items-center justify-between">
                        <span class="font-semibold">{{ league.name }}</span>
                        <span
                            class="rounded bg-slate-800 px-2 py-0.5 text-xs font-bold text-green-400"
                        >
                            {{ league.code }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-slate-400">
                        {{ league.country }} · {{ league.teams_count }} teams
                    </p>
                </li>
            </ul>
            <p v-if="!leagues.length" class="text-slate-400">
                No leagues seeded yet. Run
                <code class="rounded bg-slate-800 px-1.5 py-0.5 text-green-400">php artisan db:seed</code>.
            </p>
        </main>

        <footer class="mt-10 border-t border-slate-800 pt-4 text-xs text-slate-500">
            Africode Football AI — predictions read from cached data only.
        </footer>
    </div>
</template>
