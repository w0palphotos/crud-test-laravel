import Alpine from 'alpinejs';

// Alpine is global because the Blade templates reference it through x-data
// attributes, which have no import path. The small amount of interactivity here is
// the delete and delete-account confirmations, the assistant bubble, plus closing
// them on Escape.
window.Alpine = Alpine;

// The assistant bubble. It posts a message to the /assistant endpoint, which
// prompts Gemini and returns the reply as text. Conversations are remembered
// server-side per signed-in user, so a follow-up question keeps its context.
//
// Alpine evaluates the x-data expression as JavaScript, so
// `assistant('/assistant', 'token')` calls the provider with TWO positional
// arguments, and whatever is passed is not part of the component's reactive
// state until it is also declared as a property. Both mistakes were made here and
// both look identical from the browser: `this.endpoint` ends up undefined and
// fetch() POSTs to "/undefined".
//
// The arguments are therefore normalised to an object and then declared, so the
// component works whether it is called with positional arguments or with a single
// object, and either way `this.endpoint` is a real value.
Alpine.data('assistant', (first, second) => {
    const options = typeof first === 'object' && first !== null
        ? first
        : { endpoint: first, csrfToken: second };

    return {
        endpoint: options.endpoint,
        csrfToken: options.csrfToken,
        open: false,
        pending: false,
        draft: '',
        messages: [],
        error: '',

        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => this.$refs.input?.focus());
            }
        },

        close() {
            this.open = false;
        },

        scrollToEnd() {
            this.$nextTick(() => {
                const list = this.$refs.list;
                if (list) {
                    list.scrollTop = list.scrollHeight;
                }
            });
        },

        async send() {
            const message = this.draft.trim();

            if (!message || this.pending) {
                return;
            }

            this.messages.push({ role: 'user', text: message });
            this.draft = '';
            this.error = '';
            this.pending = true;
            this.scrollToEnd();

            try {
                const response = await fetch(this.endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: JSON.stringify({ message }),
                });

                if (response.status === 419) {
                    this.error = 'Sesi Anda sudah berakhir. Muat ulang halaman lalu coba lagi.';
                } else if (response.status === 429) {
                    this.error = 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.';
                } else if (response.status === 401) {
                    this.error = 'Silakan masuk kembali untuk menggunakan asisten.';
                } else {
                    const data = await response.json();

                    if (!response.ok) {
                        const detail = Array.isArray(data.errors) ? data.errors.join(' ') : data.error;
                        this.error = detail || 'Maaf, permintaan tidak dapat diproses.';
                    } else {
                        this.messages.push({ role: 'assistant', text: data.reply });
                    }
                }
            } catch {
                this.error = 'Tidak dapat menghubungi server.';
            } finally {
                this.pending = false;
                this.scrollToEnd();
            }
        },
    };
});

Alpine.start();