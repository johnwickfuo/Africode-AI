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
    <!-- Launcher -->
    <button
        v-show="!open"
        type="button"
        class="fixed bottom-4 right-4 z-20 flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-slate-950 shadow-lg shadow-green-500/20 transition hover:bg-green-400"
        aria-label="Open the Africode assistant chat"
        @click="toggle"
    >
        <svg viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6" aria-hidden="true">
            <path d="M12 3C6.99 3 3 6.58 3 11c0 2.23 1.02 4.23 2.68 5.68-.13 1.09-.57 2.31-1.5 3.32 1.86-.14 3.34-.83 4.4-1.58.98.36 2.05.58 3.42.58 5.01 0 9-3.58 9-8s-3.99-8-9-8z" />
        </svg>
    </button>

    <!-- Panel -->
    <div
        v-show="open"
        class="fixed inset-x-2 bottom-2 z-20 flex h-[70vh] max-h-[32rem] flex-col overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl sm:inset-x-auto sm:right-4 sm:bottom-4 sm:w-96"
        role="dialog"
        aria-label="Africode assistant chat"
    >
        <header class="flex items-center justify-between border-b border-slate-800 bg-slate-950/60 px-4 py-3">
            <div class="flex items-center gap-2">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-green-500 text-xs font-black text-slate-950">A</span>
                <div>
                    <p class="text-sm font-bold leading-tight">Africode assistant</p>
                    <p class="text-xs text-slate-400">Top-5 leagues · 2023-24 onwards</p>
                </div>
            </div>
            <button
                type="button"
                class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-800 hover:text-slate-200"
                aria-label="Close chat"
                @click="toggle"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
                </svg>
            </button>
        </header>

        <div ref="scroller" class="flex-1 space-y-3 overflow-y-auto px-4 py-3">
            <template v-if="messages.length === 0">
                <p class="text-sm text-slate-400">
                    Ask me about fixtures, predictions, team stats, referees, or how
                    accurate the models have been.
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="starter in starters"
                        :key="starter"
                        type="button"
                        class="rounded-full bg-slate-800 px-3 py-1.5 text-xs font-semibold text-slate-300 transition hover:bg-slate-700"
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
                    class="max-w-[85%] whitespace-pre-line rounded-2xl px-3.5 py-2 text-sm leading-relaxed"
                    :class="message.role === 'user'
                        ? 'rounded-br-sm bg-green-500 font-medium text-slate-950'
                        : 'rounded-bl-sm bg-slate-800 text-slate-100'"
                >
                    {{ message.text }}
                </p>
            </div>

            <div v-if="sending" class="flex justify-start" aria-label="Assistant is typing">
                <span class="flex gap-1 rounded-2xl rounded-bl-sm bg-slate-800 px-4 py-3">
                    <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-400" style="animation-delay: 0ms" />
                    <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-400" style="animation-delay: 120ms" />
                    <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-400" style="animation-delay: 240ms" />
                </span>
            </div>
        </div>

        <form class="border-t border-slate-800 p-3" @submit.prevent="send()">
            <div class="flex items-end gap-2">
                <textarea
                    ref="inputEl"
                    v-model="input"
                    rows="1"
                    :maxlength="MAX_LENGTH"
                    placeholder="Ask about stats, fixtures, picks…"
                    class="max-h-24 min-h-[2.5rem] flex-1 resize-none rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 focus:border-green-500 focus:outline-none"
                    @keydown.enter.exact.prevent="send()"
                />
                <button
                    type="submit"
                    :disabled="sending || !input.trim()"
                    class="rounded-xl bg-green-500 p-2.5 text-slate-950 transition enabled:hover:bg-green-400 disabled:opacity-40"
                    aria-label="Send message"
                >
                    <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path d="M3.4 20.4l17.45-7.48a1 1 0 000-1.84L3.4 3.6a.993.993 0 00-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z" />
                    </svg>
                </button>
            </div>
            <p v-if="input.length > MAX_LENGTH - 60" class="mt-1 text-right text-xs text-slate-500">
                {{ MAX_LENGTH - input.length }} characters left
            </p>
        </form>
    </div>
</template>
