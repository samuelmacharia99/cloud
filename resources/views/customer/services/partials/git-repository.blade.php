@if (!empty($supportsGitRepository) && $deployment)
@php
    $gitUrl = $gitRepository['url'] ?? '';
    $gitBranch = $gitRepository['branch'] ?? 'main';
    $gitSyncedAt = $gitRepository['synced_at'] ?? null;
@endphp
<div
    x-data="gitPullPanel()"
    x-init="init()"
    class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900/40 p-6 space-y-5"
>
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div class="max-w-3xl">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">GitHub repository</h3>
            <p class="text-sm text-slate-600 dark:text-slate-300 mt-2">
                Connect a Git repository and pull the latest code into <code class="font-mono text-xs">/app</code>
                without using the terminal. Use an HTTPS URL; for private repositories include a personal access token in the URL.
            </p>
        </div>
    </div>

    <form method="POST" action="{{ route('customer.services.container.git-repository.update', $service) }}" class="grid lg:grid-cols-2 gap-4">
        @csrf
        <div>
            <label for="source_repo_url" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Repository URL</label>
            <input
                id="source_repo_url"
                type="url"
                name="source_repo_url"
                value="{{ old('source_repo_url', $gitUrl) }}"
                placeholder="https://github.com/your-org/your-app.git"
                required
                class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-950 px-3 py-2 text-sm"
            >
        </div>
        <div>
            <label for="source_repo_branch" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Branch</label>
            <input
                id="source_repo_branch"
                type="text"
                name="source_repo_branch"
                value="{{ old('source_repo_branch', $gitBranch) }}"
                placeholder="main"
                class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-950 px-3 py-2 text-sm"
            >
        </div>
        <div class="lg:col-span-2">
            <button
                type="submit"
                class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 text-white text-sm font-medium"
            >
                Save repository
            </button>
        </div>
    </form>

    @if ($gitUrl !== '')
        <div class="border-t border-slate-200 dark:border-slate-700 pt-5 space-y-4">
            <div class="text-sm text-slate-600 dark:text-slate-300">
                <p><span class="font-medium text-slate-900 dark:text-white">Connected:</span> {{ $gitUrl }}</p>
                <p><span class="font-medium text-slate-900 dark:text-white">Branch:</span> <span x-text="repository.branch || @js($gitBranch)"></span></p>
                <p x-show="repository.synced_at || @js((bool) $gitSyncedAt)" class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    <span x-text="repository.synced_at ? `Last synced ${formatSyncedAt(repository.synced_at)}` : @js($gitSyncedAt ? 'Last synced '.$gitSyncedAt : '')"></span>
                    <span x-show="pull?.commit" x-text="pull?.commit ? ` · commit ${pull.commit}` : ''"></span>
                </p>
            </div>

            <div class="space-y-3">
                <div class="flex flex-col gap-2 text-sm text-slate-700 dark:text-slate-300">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" x-model="replaceExisting" class="rounded border-slate-300 dark:border-slate-600">
                        Replace /app contents on first clone (required if /app was created by Initialize Laravel)
                    </label>
                    @if (!empty($isLaravelTemplate))
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" x-model="runComposer" class="rounded border-slate-300 dark:border-slate-600">
                            Run <code class="font-mono text-xs">composer install</code> after pull
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" x-model="runMigrations" class="rounded border-slate-300 dark:border-slate-600">
                            Run <code class="font-mono text-xs">php artisan migrate</code> after pull
                        </label>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        @click="startPull()"
                        :disabled="!canPull || pulling"
                        class="px-5 py-2.5 rounded-lg font-medium transition"
                        :class="canPull && !pulling ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-slate-300 dark:bg-slate-700 text-slate-500 cursor-not-allowed'"
                    >
                        <span x-text="pulling ? 'Starting pull…' : (isActive ? 'Pull in progress…' : 'Pull latest from Git')"></span>
                    </button>
                    <p x-show="!canPull && !isActive" class="text-xs text-slate-500 dark:text-slate-400">Start the container before pulling from Git.</p>
                    <p x-show="errorMessage" class="text-sm text-red-600 dark:text-red-400" x-text="errorMessage"></p>
                </div>
            </div>

            <div x-show="pull" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-950/40 p-5 space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Pull progress</h4>
                    <span
                        x-show="pull"
                        class="text-xs font-semibold px-2 py-1 rounded-full"
                        :class="{
                            'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300': pull?.status === 'running' || pull?.status === 'pending',
                            'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300': pull?.status === 'completed',
                            'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300': pull?.status === 'failed',
                        }"
                        x-text="pull?.status || 'idle'"
                    ></span>
                </div>

                <template x-if="pull?.steps?.length">
                    <ol class="space-y-2">
                        <template x-for="step in pull.steps" :key="step.key">
                            <li class="flex items-start gap-3 rounded-lg bg-white/80 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 p-3">
                                <span class="mt-0.5 text-lg" x-text="statusIcon(step.status)"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-slate-900 dark:text-white" x-text="step.label"></p>
                                    <p x-show="step.message" class="text-xs text-slate-500 dark:text-slate-400 mt-1" x-text="step.message"></p>
                                </div>
                            </li>
                        </template>
                    </ol>
                </template>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-2">Pull log</p>
                    <pre
                        class="text-xs font-mono bg-slate-900 text-slate-100 rounded-lg p-4 h-64 overflow-auto whitespace-pre-wrap"
                        x-text="logOutput"
                    ></pre>
                </div>

                <p x-show="pull?.error_message" class="text-sm text-red-600 dark:text-red-400" x-text="pull?.error_message"></p>
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script>
function gitPullPanel() {
    return {
        repository: {
            branch: @js($gitBranch),
            synced_at: @js($gitSyncedAt),
        },
        pull: null,
        logOutput: 'No Git pull has been run yet.',
        replaceExisting: false,
        runComposer: true,
        runMigrations: true,
        pulling: false,
        errorMessage: '',
        pollTimer: null,
        canPull: {{ $deployment->isRunning() ? 'true' : 'false' }},

        get isActive() {
            return this.pull && ['pending', 'running'].includes(this.pull.status);
        },

        init() {
            this.refresh();
            this.schedulePoll();
        },

        schedulePoll() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
            }

            const intervalMs = this.isActive ? 3000 : 15000;
            this.pollTimer = setInterval(() => this.refresh(), intervalMs);
        },

        async refresh() {
            try {
                const response = await fetch(`{{ route('customer.services.container.git-repository.status', $service) }}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json();
                const previousStatus = this.pull?.status;
                this.repository = data.repository || this.repository;
                this.pull = data.pull || this.pull;
                this.logOutput = this.pull?.log || 'No Git pull has been run yet.';

                const nowActive = this.isActive;
                const wasActive = previousStatus && ['pending', 'running'].includes(previousStatus);
                if (nowActive !== wasActive || (nowActive && this.pull?.status !== previousStatus)) {
                    this.schedulePoll();
                }

                if (this.pull?.status === 'completed') {
                    this.errorMessage = '';
                }
            } catch (error) {
                console.error('Failed to refresh Git pull status', error);
            }
        },

        async startPull() {
            if (!this.canPull || this.pulling || this.isActive) {
                return;
            }

            const confirmed = await window.appConfirm(
                'Pull the latest code from Git into /app?',
                'Pull from Git',
                'Pull'
            );
            if (!confirmed) {
                return;
            }

            this.pulling = true;
            this.errorMessage = '';

            try {
                const response = await fetch(`{{ route('customer.services.container.git-repository.pull', $service) }}`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        replace_existing: this.replaceExisting,
                        run_composer: this.runComposer,
                        run_migrations: this.runMigrations,
                    }),
                });

                const data = await response.json();

                if (!response.ok) {
                    this.errorMessage = data.error || 'Failed to start Git pull.';
                    return;
                }

                this.pull = data.pull || this.pull;
                this.logOutput = this.pull?.log || 'Git pull queued…';
                this.schedulePoll();
                await this.refresh();
            } catch (error) {
                this.errorMessage = 'Failed to start Git pull.';
            } finally {
                this.pulling = false;
            }
        },

        statusIcon(status) {
            return ({
                completed: '✓',
                running: '…',
                pending: '○',
                failed: '✗',
                skipped: '–',
                warning: '!',
            })[status] || '○';
        },

        formatSyncedAt(iso) {
            if (!iso) return '';
            const date = new Date(iso);
            if (Number.isNaN(date.getTime())) return iso;
            return date.toLocaleString();
        },
    };
}
</script>
@endpush
@endif
