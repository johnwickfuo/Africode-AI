<script setup>
import { nextTick, ref } from 'vue';
import axios from 'axios';

const MAX_LENGTH = 500;

const open = ref(false);
const input = ref('');
const sending = ref(false);
const messages = ref([]);
const scroller = ref(null);
const inputEl = ref(null);

const starters = [
    'When do Arsenal play next?',
    'Best bets this weekend?',
    'How accurate are the corner picks?',
];

const scrollToBottom = async () => {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: 'smooth' });
};

const toggle = async () => {
    open.value = !open.value;
    if (open.value) {
        await nextTick();
        inputEl.value?.focus();
    }
};

const send = async (text) => {
    const message = (text ?? input.value).trim();
    if (!message || sending.value || message.length > MAX_LENGTH) return;

    messages.value.push({ role: 'user', text: message });
    input.value = '';
    sending.value = true;
    scrollToBottom();

    try {
        const { data } = await axios.post('/api/chat', { message });
        messages.value.push({ role: 'bot', text: data.reply });
    } catch (error) {
        messages.value.push({
            role: 'bot',
            text: error.response?.data?.reply
                ?? 'Something went wrong — please try again in a moment.',
        });
    } finally {
        sending.value = false;
        scrollToBottom();
        inputEl.value?.focus();
    }
};
</script>

<template>
    <!-- Launcher: clears the mobile tab bar, sits low on desktop -->
    <button
        v-show="!open"
        type="button"
        class="fixed right-4 z-40 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-gradient text-ink-950 shadow-glow transition hover:scale-105 active:scale-95 bottom-safe-nav md:bottom-safe"
        aria-label="Open the Africode assistant"
        @click="toggle"
    >
        <svg viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6" aria-hidden="true">
            <path d="M12 3C6.99 3 3 6.58 3 11c0 2.23 1.02 4.23 2.68 5.68-.13 1.09-.57 2.31-1.5 3.32 1.86-.14 3.34-.83 4.4-1.58.98.36 2.05.58 3.42.58 5.01 0 9-3.58 9-8s-3.99-8-9-8z" />
        </svg>
    </button>

    <!-- Panel -->
    <div
        v-show="open"
        class="fixed inset-x-2 bottom-2 z-40 flex h-[76vh] max-h-[34rem] flex-col overflow-hidden rounded-2xl border border-ink-700 bg-ink-900 shadow-lift sm:inset-x-auto sm:bottom-4 sm:right-4 sm:w-[24rem]"
        role="dialog"
        aria-label="Africode assistant"
    >
        <header class="flex items-center justify-between gap-3 border-b border-ink-800 bg-ink-950/70 px-4 py-3">
            <div class="flex min-w-0 items-center gap-2.5">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-brand-gradient text-sm font-black text-ink-950">
                    A
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-bold leading-tight text-ink-100">Africode assistant</p>
                    <p class="truncate text-[11px] text-ink-500">12 leagues · 2023-24 onwards</p>
                </div>
            </div>
            <button
                type="button"
                class="rounded-lg p-2 text-ink-400 transition hover:bg-ink-800 hover:text-ink-100"
                aria-label="Close chat"
                @click="toggle"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
                </svg>
            </button>
        </header>

        <div ref="scroller" class="flex-1 space-y-3 overflow-y-auto px-4 py-4">
            <template v-if="messages.length === 0">
                <p class="text-sm leading-relaxed text-ink-400">
                    Ask about fixtures, predictions, team or player stats, referees — or how
                    accurate the models have actually been.
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="starter in starters"
                        :key="starter"
                        type="button"
                        class="rounded-full border border-ink-700 bg-ink-850 px-3 py-1.5 text-xs font-semibold text-ink-300 transition hover:border-brand-500/40 hover:text-brand-300"
                        @click="send(starter)"
                    >
                        {{ starter }}
                    </button>
                </div>
            </template>

            <div
                v-for="(message, index) in messages"
                :key="index"
                class="flex"
                :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
            >
                <p
                    class="max-w-[85%] whitespace-pre-line rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed"
                    :class="message.role === 'user'
                        ? 'rounded-br-md bg-brand-gradient font-medium text-ink-950'
                        : 'rounded-bl-md bg-ink-800 text-ink-100'"
                >
                    {{ message.text }}
                </p>
            </div>

            <div v-if="sending" class="flex justify-start" aria-label="Assistant is typing">
                <span class="flex gap-1 rounded-2xl rounded-bl-md bg-ink-800 px-4 py-3">
                    <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-ink-400" style="animation-delay: 0ms" />
                    <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-ink-400" style="animation-delay: 120ms" />
                    <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-ink-400" style="animation-delay: 240ms" />
                </span>
            </div>
        </div>

        <form class="border-t border-ink-800 bg-ink-950/40 p-3" @submit.prevent="send()">
            <div class="flex items-end gap-2">
                <textarea
                    ref="inputEl"
                    v-model="input"
                    rows="1"
                    :maxlength="MAX_LENGTH"
                    placeholder="Ask about stats, fixtures, picks…"
                    class="max-h-24 min-h-[2.75rem] flex-1 resize-none rounded-xl border border-ink-700 bg-ink-950 px-3.5 py-2.5 text-sm text-ink-100 placeholder:text-ink-500 focus:border-brand-500 focus:outline-none"
                    @keydown.enter.exact.prevent="send()"
                />
                <button
                    type="submit"
                    :disabled="sending || !input.trim()"
                    class="rounded-xl bg-brand-gradient p-3 text-ink-950 transition enabled:hover:brightness-110 disabled:opacity-40"
                    aria-label="Send message"
                >
                    <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path d="M3.4 20.4l17.45-7.48a1 1 0 000-1.84L3.4 3.6a.993.993 0 00-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z" />
                    </svg>
                </button>
            </div>
            <p v-if="input.length > MAX_LENGTH - 60" class="mt-1.5 text-right text-xs text-ink-500">
                {{ MAX_LENGTH - input.length }} characters left
            </p>
        </form>
    </div>
</template>
