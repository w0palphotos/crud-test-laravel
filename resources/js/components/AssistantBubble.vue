<script setup>
/**
 * The assistant bubble: a fixed launcher that opens a chat panel.
 *
 * It posts to /assistant, which prompts Gemini and returns the reply as text.
 * Conversations are remembered server-side per signed-in user, so a follow-up
 * question keeps its context.
 *
 * The request goes through useHttp rather than fetch. useHttp is Inertia's own
 * client: it carries the XSRF cookie, so this no longer needs the csrf-token meta
 * tag, and it deliberately does not trigger a page visit, which is what a chat
 * message must not do. A hand-rolled fetch here would have meant re-implementing
 * both.
 */
import { nextTick, ref } from 'vue';
import { useHttp } from '@inertiajs/vue3';

const props = defineProps({
    endpoint: { type: String, required: true },
});

/**
 * Wording for the statuses a reader can act on. Anything not listed falls
 * through to the server's own message, which is more specific than anything
 * guessed here.
 */
const MESSAGES = {
    401: 'Silakan masuk kembali.',
    419: 'Sesi Anda sudah berakhir. Muat ulang halaman lalu coba lagi.',
    429: 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.',
};

const form = useHttp({ message: '' });

const open = ref(false);
const messages = ref([]);
const error = ref('');

const list = ref(null);
const input = ref(null);

const draft = form.message;
const pending = form.processing;

function toggle() {
    open.value = !open.value;

    if (open.value) {
        nextTick(() => input.value?.focus());
    }
}

function close() {
    open.value = false;
}

function scrollToEnd() {
    nextTick(() => {
        if (list.value) {
            list.value.scrollTop = list.value.scrollHeight;
        }
    });
}

/**
 * Shift+Enter inserts a newline, plain Enter sends. Suppressing the default on
 * Enter stops the browser also inserting one before the message is posted.
 */
function onKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        send();
    }
}

async function send() {
    const message = draft.value.trim();

    // A pending request means the provider is still working; sending again would
    // open a second turn on the same conversation for no gain.
    if (!message || pending.value) {
        return;
    }

    messages.value.push({ role: 'user', text: message });
    draft.value = '';
    error.value = '';
    scrollToEnd();

    try {
        const data = await form.post(props.endpoint);

        messages.value.push({ role: 'assistant', text: data.reply });
    } catch (failure) {
        const status = failure.response?.status;

        error.value = MESSAGES[status] ?? failure.message ?? 'Tidak dapat menghubungi server.';
    } finally {
        scrollToEnd();
    }
}
</script>

<template>
    <div class="fixed bottom-4 right-4 z-50 sm:bottom-6 sm:right-6" @keydown.esc.window="close">
        <Transition
            enter-active-class="transition ease-out duration-150"
            enter-from-class="translate-y-2 opacity-0"
            leave-active-class="transition ease-in duration-100"
            leave-to-class="translate-y-2 opacity-0"
        >
            <!-- v-show rather than v-if, so the panel keeps its scroll position and
                 the open/close animation applies to an existing element. -->
            <div
                v-show="open"
                id="assistant-panel"
                class="absolute bottom-16 right-0 flex h-[26rem] w-[calc(100vw-2rem)] max-w-sm flex-col overflow-hidden rounded-xl border border-neutral-200 bg-admin-surface shadow-xl"
                role="dialog"
                aria-label="Asisten"
            >
                <div class="flex items-center justify-between border-b border-neutral-200 bg-neutral-50 px-4 py-3">
                    <div>
                        <p class="text-sm font-semibold tracking-tight">Asisten</p>
                        <p class="text-xs text-neutral-600">Tanya apa saja, dalam Bahasa Indonesia.</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md p-1 text-neutral-500 hover:bg-neutral-200 hover:text-neutral-900"
                        aria-label="Tutup asisten"
                        @click="close"
                    >
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                    </button>
                </div>

                <div ref="list" class="flex-1 space-y-3 overflow-y-auto px-4 py-4">
                    <div
                        v-for="(item, index) in messages"
                        :key="index"
                        class="flex"
                        :class="item.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <p
                            class="max-w-[85%] rounded-lg px-3 py-2 text-sm"
                            :class="item.role === 'user' ? 'bg-admin-primary text-white' : 'bg-neutral-100 text-neutral-900'"
                        >
                            {{ item.text }}
                        </p>
                    </div>

                    <div v-if="pending" class="flex justify-start">
                        <p class="rounded bg-neutral-100 px-3 py-2 text-sm text-neutral-600">Mengerti…</p>
                    </div>

                    <p v-if="error" class="text-sm text-rose-600">{{ error }}</p>
                </div>

                <form class="border-t border-neutral-200 p-3" @submit.prevent="send">
                    <div class="flex items-end gap-2">
                        <label for="assistant-message" class="sr-only">Pertanyaan</label>
                        <textarea
                            id="assistant-message"
                            ref="input"
                            v-model="draft"
                            rows="2"
                            maxlength="500"
                            placeholder="Contoh: apa itu aplikasi ini?"
                            class="w-full resize-none rounded-md border border-neutral-300 px-3 py-2 text-sm placeholder:text-neutral-400 focus:border-admin-primary focus:outline-none"
                            @keydown="onKeydown"
                        ></textarea>
                        <button
                            type="submit"
                            :disabled="pending || !draft.trim()"
                            class="shrink-0 rounded-md bg-admin-primary px-3 py-2 text-sm font-medium text-white hover:bg-admin-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Kirim
                        </button>
                    </div>
                </form>
            </div>
        </Transition>

        <button
            type="button"
            class="flex size-13 items-center justify-center rounded-full bg-admin-primary text-white shadow-lg hover:bg-neutral-700"
            :aria-expanded="open"
            aria-controls="assistant-panel"
            aria-label="Buka asisten"
            @click="toggle"
        >
            <svg v-if="!open" class="size-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 3c-4.97 0-9 3.363-9 7.5 0 2.29 1.19 4.36 3.1 5.74L5.5 20l3.86-1.62c.85.18 1.74.28 2.64.28 4.97 0 9-3.363 9-7.5S16.97 3 12 3Z" />
            </svg>
            <svg v-else class="size-6" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
            </svg>
        </button>
    </div>
</template>