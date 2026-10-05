{{--
    The assistant bubble: a fixed launcher in the bottom-right that opens a small
    chat panel.

    It is an anonymous component so it can be included from any layout without
    threading an endpoint through. Only rendered where a signed-in user exists,
    because the endpoint calls a metered API. The Alpine component that backs it
    lives in resources/js/app.js as `assistant`.
--}}
<div
    x-data="assistant(@js(route('assistant.store')), @js(csrf_token()))"
    x-on:keydown.escape.window="close()"
    class="fixed bottom-4 right-4 z-50 sm:bottom-6 sm:right-6"
>
    {{-- Panel --}}
    <div
        id="assistant-panel"
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="translate-y-2 opacity-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-end="translate-y-2 opacity-0"
        class="absolute bottom-16 right-0 flex h-[26rem] w-[calc(100vw-2rem)] max-w-sm flex-col overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-xl"
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
                x-on:click="close()"
                class="rounded-md p-1 text-neutral-500 hover:bg-neutral-200 hover:text-neutral-900"
                aria-label="Tutup asisten"
            >
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                </svg>
            </button>
        </div>

        {{-- Messages --}}
        <div x-ref="list" class="flex-1 space-y-3 overflow-y-auto px-4 py-4">
            <template x-for="(item, index) in messages" :key="index">
                <div class="flex" :class="item.role === 'user' ? 'justify-end' : 'justify-start'">
                    <p
                        class="max-w-[85%] rounded-lg px-3 py-2 text-sm"
                        :class="item.role === 'user'
                            ? 'bg-neutral-900 text-white'
                            : 'bg-neutral-100 text-neutral-900'"
                        x-text="item.text"
                    ></p>
                </div>
            </template>

            <div x-show="pending" class="flex justify-start">
                <p class="rounded-lg bg-neutral-100 px-3 py-2 text-sm text-neutral-600">Mengerti…</p>
            </div>

            <p x-show="error" x-cloak class="text-sm text-rose-600" x-text="error"></p>
        </div>

        {{-- Composer --}}
        <form class="border-t border-neutral-200 p-3" x-on:submit.prevent="send()">
            <div class="flex items-end gap-2">
                <label for="assistant-message" class="sr-only">Pertanyaan</label>
                <textarea
                    id="assistant-message"
                    x-ref="input"
                    x-model="draft"
                    rows="2"
                    maxlength="500"
                    x-on:keydown.enter.prevent="if (!$event.shiftKey) send()"
                    placeholder="Contoh: apa itu aplikasi ini?"
                    class="w-full resize-none rounded-md border border-neutral-300 px-3 py-2 text-sm placeholder:text-neutral-400 focus:border-neutral-900 focus:outline-none"
                ></textarea>
                <button
                    type="submit"
                    x-bind:disabled="pending || !draft.trim()"
                    class="shrink-0 rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Kirim
                </button>
            </div>
        </form>
    </div>

    {{-- Launcher --}}
    <button
        type="button"
        x-on:click="toggle()"
        x-bind:aria-expanded="open"
        aria-controls="assistant-panel"
        class="flex size-13 items-center justify-center rounded-full bg-neutral-900 text-white shadow-lg hover:bg-neutral-700"
        aria-label="Buka asisten"
    >
        <svg x-show="!open" class="size-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 3c-4.97 0-9 3.363-9 7.5 0 2.29 1.19 4.36 3.1 5.74L5.5 20l3.86-1.62c.85.18 1.74.28 2.64.28 4.97 0 9-3.363 9-7.5S16.97 3 12 3Z" />
        </svg>
        <svg x-show="open" x-cloak class="size-6" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
        </svg>
    </button>
</div>