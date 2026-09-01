@if (!empty($supportsOllamaChat) && !empty($ollamaChatPanel))
<div
    class="space-y-4"
    x-data="ollamaChatPanel()"
    x-init="init()"
>
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
            <h3 class="text-xl font-bold text-slate-900 dark:text-white">Chat</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 max-w-3xl">
                Talk to the model running in this container. The Terminal is a Linux shell — questions like “hello” belong here.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <label class="text-xs font-medium text-slate-500 dark:text-slate-400" for="ollama-model">Model</label>
            <select
                id="ollama-model"
                x-model="model"
                :disabled="busy || models.length === 0"
                class="rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm px-3 py-2"
            >
                <template x-for="name in models" :key="name">
                    <option :value="name" x-text="name"></option>
                </template>
                <option x-show="models.length === 0" :value="model" x-text="model"></option>
            </select>
        </div>
    </div>

    <div
        x-show="!containerRunning"
        class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-amber-800 dark:text-amber-200 text-sm"
    >
        Start the app before chatting with the model.
    </div>

    <div
        x-show="warningMessage"
        x-cloak
        class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-amber-800 dark:text-amber-200 text-sm"
        x-text="warningMessage"
    ></div>

    <div
        x-show="errorMessage"
        x-cloak
        class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4 text-red-800 dark:text-red-200 text-sm"
        x-text="errorMessage"
    ></div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-950 overflow-hidden flex flex-col" style="min-height: 28rem;">
        <div x-ref="transcript" class="flex-1 overflow-y-auto p-4 space-y-3" style="max-height: 28rem;">
            <div x-show="messages.length === 0" class="text-sm text-slate-400 py-8 text-center">
                Ask a question. First replies can take a minute while the model loads into memory.
            </div>
            <template x-for="(entry, index) in messages" :key="index">
                <div :class="entry.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                    <div
                        class="max-w-[85%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap break-words"
                        :class="entry.role === 'user'
                            ? 'bg-blue-600 text-white'
                            : 'bg-slate-800 text-slate-100 border border-slate-700'"
                        x-text="entry.content"
                    ></div>
                </div>
            </template>
            <div x-show="busy" class="flex justify-start">
                <div class="rounded-2xl px-4 py-2.5 text-sm bg-slate-800 text-slate-300 border border-slate-700">
                    Thinking…
                </div>
            </div>
        </div>

        <form @submit.prevent="send()" class="border-t border-slate-800 p-3 flex gap-2 items-end bg-slate-900">
            <textarea
                x-model="draft"
                @keydown.enter.prevent="if (!$event.shiftKey) send()"
                :disabled="busy || !containerRunning"
                rows="2"
                maxlength="{{ \App\Services\Provisioning\ContainerOllamaModelService::MAX_MESSAGE_CHARS }}"
                placeholder="Message the model…"
                class="flex-1 resize-none rounded-lg border-0 bg-slate-800 text-slate-100 text-sm px-3 py-2 focus:ring-1 focus:ring-blue-500 disabled:opacity-50"
            ></textarea>
            <button
                type="submit"
                :disabled="busy || !containerRunning || !draft.trim()"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-40 text-white rounded-lg text-sm font-medium"
            >
                Send
            </button>
        </form>
    </div>
</div>

@push('scripts')
<script>
function ollamaChatPanel() {
    const modelsUrl = @json(route('customer.services.container.ollama.models', $service));
    const chatUrl = @json(route('customer.services.container.ollama.chat', $service));
    const csrf = document.head.querySelector('meta[name="csrf-token"]')?.content;

    return {
        containerRunning: @json((bool) ($ollamaChatPanel['container_running'] ?? false)),
        model: @json($ollamaChatPanel['default_model'] ?? 'mistral:7b'),
        models: [],
        messages: [],
        draft: '',
        busy: false,
        errorMessage: '',
        warningMessage: '',

        async init() {
            if (!this.containerRunning) {
                return;
            }

            try {
                const response = await fetch(modelsUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await response.json().catch(() => ({}));
                if (Array.isArray(data.models) && data.models.length > 0) {
                    this.models = data.models;
                }
                if (data.default_model) {
                    this.model = data.default_model;
                }
                if (data.warning) {
                    this.warningMessage = data.warning;
                }
            } catch (e) {
                this.warningMessage = 'Could not list installed models yet. You can still send a message.';
            }
        },

        async send() {
            const text = this.draft.trim();
            if (!text || this.busy || !this.containerRunning) {
                return;
            }

            this.errorMessage = '';
            this.messages.push({ role: 'user', content: text });
            this.draft = '';
            this.busy = true;
            this.scrollTranscript();

            const history = this.messages
                .slice(0, -1)
                .filter((entry) => entry.role === 'user' || entry.role === 'assistant')
                .slice(-{{ \App\Services\Provisioning\ContainerOllamaModelService::MAX_HISTORY }});

            try {
                const response = await fetch(chatUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        message: text,
                        model: this.model,
                        history,
                    }),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    this.errorMessage = data.error || 'The model could not reply.';
                    return;
                }

                const content = data.message?.content || '';
                if (content === '') {
                    this.errorMessage = 'The model returned an empty reply.';
                    return;
                }

                this.messages.push({ role: 'assistant', content });
                if (data.model) {
                    this.model = data.model;
                }
            } catch (e) {
                this.errorMessage = 'Network error while talking to the model.';
            } finally {
                this.busy = false;
                this.scrollTranscript();
            }
        },

        scrollTranscript() {
            this.$nextTick(() => {
                const el = this.$refs.transcript;
                if (el) {
                    el.scrollTop = el.scrollHeight;
                }
            });
        },
    };
}
</script>
@endpush
@endif
