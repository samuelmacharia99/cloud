@if (!empty($isLaravelTemplate) && $deployment)
<div
    x-data="laravelSetupPanel()"
    x-init="init()"
    class="rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50/70 dark:bg-indigo-950/30 p-6 space-y-5"
>
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div class="max-w-3xl">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Laravel application setup</h3>
            <p class="text-sm text-slate-600 dark:text-slate-300 mt-2">
                <strong>Redeploy stack</strong> recreates the container runtime and keeps files in <code class="font-mono text-xs">/app</code>.
                It does <strong>not</strong> install Laravel. Use <strong>Initialize Laravel app</strong> once to scaffold the project into the file manager,
                or set a Git repository at checkout and redeploy to pull code.
            </p>
        </div>

        <div class="flex flex-col sm:flex-row gap-2 shrink-0">
            <form
                method="POST"
                action="{{ route('customer.services.container.clear-app', $service) }}"
                x-show="canClearApp"
                @submit="return confirm('Remove all files in /app except Talksasa system files? This cannot be undone.');"
            >
                @csrf
                <button
                    type="submit"
                    class="px-4 py-2.5 rounded-lg font-medium transition bg-amber-100 hover:bg-amber-200 text-amber-900 dark:bg-amber-900/40 dark:hover:bg-amber-900/60 dark:text-amber-100"
                >
                    Clear /app
                </button>
            </form>

            <form
                method="POST"
                action="{{ route('customer.services.container.initialize-laravel', $service) }}"
                @submit="if (!canInitialize) { $event.preventDefault(); return false; } return confirm('Install a fresh Laravel skeleton into /app? Existing application files will block this action.');"
            >
                @csrf
                <button
                    type="submit"
                    class="px-5 py-2.5 rounded-lg font-medium transition"
                    :class="canInitialize ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-slate-300 dark:bg-slate-700 text-slate-500 cursor-not-allowed'"
                    :disabled="!canInitialize"
                >
                    ✨ Initialize Laravel app
                </button>
            </form>
        </div>
    </div>

    <div x-show="appDirectory?.has_blocking_files" class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 text-sm text-amber-900 dark:text-amber-100">
        <strong>/app contains leftover files</strong> from a previous deploy or clone. Use <strong>Clear /app</strong> on this page, or run cleanup from the Terminal tab, before initializing Laravel.
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <div>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-3">Setup checklist</h4>
            <ul class="space-y-3">
                <template x-for="item in checklist" :key="item.key">
                    <li class="flex items-start gap-3 rounded-lg bg-white/80 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 p-3">
                        <span class="mt-0.5 text-lg" x-text="statusIcon(item.status)"></span>
                        <div>
                            <p class="font-medium text-slate-900 dark:text-white" x-text="item.label"></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1" x-text="item.description"></p>
                        </div>
                    </li>
                </template>
            </ul>
        </div>

        <div>
            <div class="flex items-center justify-between mb-3">
                <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Initialization log</h4>
                <span
                    x-show="initialization"
                    class="text-xs font-semibold px-2 py-1 rounded-full"
                    :class="{
                        'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300': initialization?.status === 'running' || initialization?.status === 'pending',
                        'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300': initialization?.status === 'completed',
                        'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300': initialization?.status === 'failed',
                    }"
                    x-text="initialization ? initialization.status : 'idle'"
                ></span>
            </div>

            <template x-if="initialization && initialization.steps">
                <ol class="mb-4 space-y-2">
                    <template x-for="step in initialization.steps" :key="step.key">
                        <li class="text-sm flex items-center gap-2 text-slate-700 dark:text-slate-300">
                            <span x-text="statusIcon(step.status)"></span>
                            <span x-text="step.label"></span>
                        </li>
                    </template>
                </ol>
            </template>

            <pre
                class="text-xs font-mono bg-slate-900 text-slate-100 rounded-lg p-4 h-56 overflow-auto whitespace-pre-wrap"
                x-text="logOutput"
            ></pre>

            <p x-show="initialization?.error_message" class="mt-3 text-sm text-red-600 dark:text-red-400" x-text="initialization?.error_message"></p>
        </div>
    </div>
</div>

@push('scripts')
<script>
function laravelSetupPanel() {
    return {
        checklist: [],
        appDirectory: null,
        initialization: null,
        logOutput: 'No initialization has been run yet.',
        pollTimer: null,
        canInitialize: {{ ($deployment->isRunning() ? 'true' : 'false') }},
        canClearApp: false,

        init() {
            this.refresh();
            this.schedulePoll();
        },

        schedulePoll() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
            }

            const initActive = this.initialization && ['pending', 'running'].includes(this.initialization.status);
            const intervalMs = initActive ? 5000 : 15000;

            this.pollTimer = setInterval(() => this.refresh(), intervalMs);
        },

        async refresh() {
            try {
                const response = await fetch(`{{ route('customer.services.container.laravel-setup', $service) }}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json();
                const previousStatus = this.initialization?.status;
                this.checklist = data.checklist || [];
                this.appDirectory = data.app_directory || null;
                this.initialization = data.initialization || null;
                this.logOutput = this.initialization?.log || 'No initialization has been run yet.';

                const initActive = this.initialization && ['pending', 'running'].includes(this.initialization.status);
                const appReady = this.checklist.some(item => item.key === 'app_source' && item.status === 'completed');
                const running = {{ $deployment->isRunning() ? 'true' : 'false' }};
                this.canInitialize = running && !initActive && !appReady;
                this.canClearApp = running && !initActive && !appReady && !!(this.appDirectory && this.appDirectory.can_clear && this.appDirectory.has_blocking_files);

                const currentStatus = this.initialization?.status;
                const wasActive = previousStatus && ['pending', 'running'].includes(previousStatus);
                if (initActive !== wasActive || (initActive && currentStatus !== previousStatus)) {
                    this.schedulePoll();
                }
            } catch (error) {
                console.error('Failed to refresh Laravel setup status', error);
            }
        },

        statusIcon(status) {
            return ({
                completed: '✅',
                running: '⏳',
                pending: '⬜',
                failed: '❌',
                warning: '⚠️',
            })[status] || '⬜';
        },
    };
}
</script>
@endpush
@endif
