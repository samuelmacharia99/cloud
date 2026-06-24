@if (!empty($supportsGitRepository) && $deployment)
@php
    $gitUrl = $gitRepository['url'] ?? '';
    $gitBranch = $gitRepository['branch'] ?? 'main';
    $gitSyncedAt = $gitRepository['synced_at'] ?? null;
@endphp

<style>
    @keyframes git-mesh-shift {
        0%, 100% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
    }
    @keyframes git-shimmer {
        0% { transform: translateX(-120%) skewX(-12deg); }
        100% { transform: translateX(220%) skewX(-12deg); }
    }
    @keyframes git-orbit {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    @keyframes git-pulse-ring {
        0% { transform: scale(0.85); opacity: 0.9; }
        70% { transform: scale(1.35); opacity: 0; }
        100% { transform: scale(1.35); opacity: 0; }
    }
    @keyframes git-scan {
        0% { transform: translateY(-100%); }
        100% { transform: translateY(100%); }
    }
    @keyframes git-fade-up {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes git-bar-glow {
        0%, 100% { box-shadow: 0 0 12px rgba(34, 211, 238, 0.45), 0 0 24px rgba(168, 85, 247, 0.25); }
        50% { box-shadow: 0 0 18px rgba(168, 85, 247, 0.55), 0 0 32px rgba(34, 211, 238, 0.35); }
    }
    .git-pull-mesh {
        background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 35%, #0c4a6e 70%, #134e4a 100%);
        background-size: 200% 200%;
        animation: git-mesh-shift 8s ease infinite;
    }
    .git-pull-btn-glow {
        background: linear-gradient(135deg, #06b6d4 0%, #8b5cf6 50%, #10b981 100%);
        background-size: 200% auto;
        animation: git-mesh-shift 4s linear infinite;
    }
    .git-pull-btn-glow::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.35), transparent);
        animation: git-shimmer 2.4s ease-in-out infinite;
    }
    .git-step-running {
        animation: git-bar-glow 2s ease-in-out infinite;
    }
    .git-log-terminal::before {
        content: '';
        position: absolute;
        inset: 0;
        background: repeating-linear-gradient(
            0deg,
            rgba(0, 0, 0, 0.12) 0px,
            rgba(0, 0, 0, 0.12) 1px,
            transparent 1px,
            transparent 3px
        );
        pointer-events: none;
        z-index: 1;
    }
    .git-log-terminal::after {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        height: 40%;
        background: linear-gradient(180deg, transparent, rgba(34, 211, 238, 0.04), transparent);
        animation: git-scan 4s linear infinite;
        pointer-events: none;
        z-index: 2;
    }
    .git-step-enter {
        animation: git-fade-up 0.45s ease-out both;
    }
</style>

<div
    x-data="gitPullPanel()"
    x-init="init()"
    class="relative overflow-hidden rounded-2xl border border-slate-200/80 dark:border-cyan-500/20 bg-white dark:bg-slate-950 p-6 space-y-6 shadow-sm dark:shadow-[0_0_40px_-12px_rgba(34,211,238,0.15)]"
>
    <div class="pointer-events-none absolute -top-24 -right-24 h-48 w-48 rounded-full bg-cyan-500/10 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-16 -left-16 h-40 w-40 rounded-full bg-violet-500/10 blur-3xl"></div>

    <div class="relative flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div class="max-w-3xl">
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex h-2 w-2 rounded-full bg-cyan-400 shadow-[0_0_8px_#22d3ee]"></span>
                <span class="text-[10px] font-bold uppercase tracking-[0.2em] text-cyan-600 dark:text-cyan-400">Deploy pipeline</span>
            </div>
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">GitHub repository</h3>
            <p class="text-sm text-slate-600 dark:text-slate-300 mt-2">
                Connect a Git repository and pull the latest code into <code class="font-mono text-xs px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-cyan-700 dark:text-cyan-300">/app</code>
                without using the terminal.
            </p>
        </div>
    </div>

    <form method="POST" action="{{ route('customer.services.container.git-repository.update', $service) }}" class="relative grid lg:grid-cols-2 gap-4">
        @csrf
        <div>
            <label for="source_repo_url" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Repository URL</label>
            <input
                id="source_repo_url"
                type="url"
                name="source_repo_url"
                value="{{ old('source_repo_url', $gitUrl) }}"
                placeholder="https://github.com/your-org/your-app.git"
                required
                class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900/80 px-3 py-2.5 text-sm focus:ring-2 focus:ring-cyan-500/40 focus:border-cyan-500/50 transition"
            >
        </div>
        <div>
            <label for="source_repo_branch" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Branch</label>
            <input
                id="source_repo_branch"
                type="text"
                name="source_repo_branch"
                value="{{ old('source_repo_branch', $gitBranch) }}"
                placeholder="main"
                class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900/80 px-3 py-2.5 text-sm font-mono focus:ring-2 focus:ring-violet-500/40 focus:border-violet-500/50 transition"
            >
        </div>
        <div class="lg:col-span-2">
            <button
                type="submit"
                class="px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 dark:bg-slate-800 dark:hover:bg-slate-700 text-white text-sm font-medium border border-slate-700 transition"
            >
                Save repository
            </button>
        </div>
    </form>

    @if ($gitUrl !== '')
        <div class="relative border-t border-slate-200 dark:border-slate-800 pt-6 space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="text-sm text-slate-600 dark:text-slate-300 space-y-1">
                    <p class="font-mono text-xs text-cyan-700 dark:text-cyan-300/90 truncate max-w-xl">{{ $gitUrl }}</p>
                    <p>
                        <span class="text-slate-500 dark:text-slate-500">branch</span>
                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-md bg-violet-500/10 text-violet-700 dark:text-violet-300 font-mono text-xs border border-violet-500/20" x-text="repository.branch || @js($gitBranch)"></span>
                    </p>
                    <p x-show="repository.synced_at || @js((bool) $gitSyncedAt)" class="text-xs text-slate-500 dark:text-slate-400">
                        <span x-text="repository.synced_at ? `Synced ${formatSyncedAt(repository.synced_at)}` : @js($gitSyncedAt ? 'Synced '.$gitSyncedAt : '')"></span>
                        <span x-show="pull?.commit" class="text-emerald-600 dark:text-emerald-400 font-mono" x-text="pull?.commit ? ` · ${pull.commit}` : ''"></span>
                    </p>
                </div>

                <div class="flex items-center gap-2 text-[10px] font-mono uppercase tracking-widest text-slate-400">
                    <span class="h-1.5 w-1.5 rounded-full" :class="isActive ? 'bg-cyan-400 animate-pulse shadow-[0_0_6px_#22d3ee]' : (pull?.status === 'completed' ? 'bg-emerald-400' : 'bg-slate-500')"></span>
                    <span x-text="isActive ? 'live sync' : (pull?.status || 'standby')"></span>
                </div>
            </div>

            <div class="space-y-4 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-900/40 p-4">
                <div class="flex flex-col gap-2.5 text-sm text-slate-700 dark:text-slate-300">
                    <label class="inline-flex items-center gap-2.5 cursor-pointer group">
                        <input type="checkbox" x-model="replaceExisting" class="rounded border-slate-300 dark:border-slate-600 text-cyan-500 focus:ring-cyan-500/40">
                        <span class="group-hover:text-slate-900 dark:group-hover:text-white transition">Replace /app on first clone</span>
                    </label>
                    @if (!empty($isLaravelTemplate))
                        <label class="inline-flex items-center gap-2.5 cursor-pointer group">
                            <input type="checkbox" x-model="runComposer" class="rounded border-slate-300 dark:border-slate-600 text-violet-500 focus:ring-violet-500/40">
                            <span class="group-hover:text-slate-900 dark:group-hover:text-white transition">Run <code class="font-mono text-xs text-cyan-600 dark:text-cyan-400">composer install</code></span>
                        </label>
                        <label class="inline-flex items-center gap-2.5 cursor-pointer group">
                            <input type="checkbox" x-model="runMigrations" class="rounded border-slate-300 dark:border-slate-600 text-emerald-500 focus:ring-emerald-500/40">
                            <span class="group-hover:text-slate-900 dark:group-hover:text-white transition">Run <code class="font-mono text-xs text-emerald-600 dark:text-emerald-400">php artisan migrate</code></span>
                        </label>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        @click="startPull()"
                        :disabled="!canPull || pulling || isActive"
                        class="relative overflow-hidden px-6 py-3 rounded-xl font-semibold text-sm text-white transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed disabled:shadow-none"
                        :class="canPull && !pulling && !isActive ? 'git-pull-btn-glow shadow-[0_0_24px_-4px_rgba(34,211,238,0.5)] hover:shadow-[0_0_32px_-2px_rgba(168,85,247,0.55)] hover:scale-[1.02] active:scale-[0.98]' : 'bg-slate-400 dark:bg-slate-700'"
                    >
                        <span class="relative z-10 flex items-center gap-2">
                            <svg x-show="isActive || pulling" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span x-text="pulling ? 'Initializing…' : (isActive ? 'Syncing…' : 'Pull latest from Git')"></span>
                        </span>
                    </button>
                    <p x-show="!canPull && !isActive" class="text-xs text-slate-500">Start the container to pull.</p>
                    <p x-show="errorMessage" x-transition class="text-sm text-red-500 dark:text-red-400 font-medium" x-text="errorMessage"></p>
                </div>
            </div>

            <div
                x-show="pull"
                x-transition:enter="transition ease-out duration-500"
                x-transition:enter-start="opacity-0 translate-y-4 scale-[0.98]"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                class="relative overflow-hidden rounded-2xl border border-cyan-500/20 dark:border-cyan-400/25 git-pull-mesh p-[1px]"
            >
                <div class="relative rounded-[15px] bg-slate-950/95 backdrop-blur-xl p-5 sm:p-6 space-y-5">
                    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(34,211,238,0.08),transparent_50%),radial-gradient(ellipse_at_bottom_left,_rgba(168,85,247,0.08),transparent_50%)]"></div>

                    <div class="relative flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div class="relative flex h-12 w-12 items-center justify-center">
                                <span x-show="isActive" class="absolute inset-0 rounded-full border border-cyan-400/40 animate-[git-pulse-ring_2s_ease-out_infinite]"></span>
                                <span x-show="isActive" class="absolute inset-1 rounded-full border border-violet-400/30 animate-[git-pulse-ring_2s_ease-out_infinite_0.5s]"></span>
                                <div class="relative h-10 w-10 rounded-full bg-gradient-to-br from-cyan-500/20 to-violet-500/20 border border-cyan-400/30 flex items-center justify-center">
                                    <svg x-show="isActive" class="h-5 w-5 text-cyan-300 animate-[git-orbit_3s_linear_infinite]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" d="M12 3v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M5.6 18.4l2.1-2.1m8.6-8.6 2.1-2.1"/>
                                    </svg>
                                    <svg x-show="!isActive && pull?.status === 'completed'" class="h-5 w-5 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    <svg x-show="!isActive && pull?.status === 'failed'" class="h-5 w-5 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    <svg x-show="!isActive && pull?.status !== 'completed' && pull?.status !== 'failed'" class="h-5 w-5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h10"/></svg>
                                </div>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.25em] text-cyan-400/80">Pipeline</p>
                                <h4 class="text-lg font-semibold text-white tracking-tight">Git sync</h4>
                                <p class="text-xs text-slate-400 mt-0.5 font-mono" x-text="progressLabel()"></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="text-right">
                                <p class="text-2xl font-bold tabular-nums bg-gradient-to-r from-cyan-300 via-violet-300 to-emerald-300 bg-clip-text text-transparent" x-text="`${progressPercent()}%`"></p>
                                <p class="text-[10px] uppercase tracking-widest text-slate-500">complete</p>
                            </div>
                            <span
                                class="text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full border"
                                :class="statusBadgeClass()"
                                x-text="pull?.status || 'idle'"
                            ></span>
                        </div>
                    </div>

                    <div class="relative h-2 rounded-full bg-slate-800/80 overflow-hidden border border-slate-700/50">
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-violet-500 to-emerald-400 transition-all duration-700 ease-out"
                            :class="isActive ? 'animate-[git-bar-glow_2s_ease-in-out_infinite]' : ''"
                            :style="`width: ${progressPercent()}%`"
                        ></div>
                        <div x-show="isActive" class="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent animate-[git-shimmer_2s_ease-in-out_infinite]"></div>
                    </div>

                    <template x-if="pull?.steps?.length">
                        <ol class="relative space-y-2">
                            <template x-for="(step, index) in pull.steps" :key="step.key">
                                <li
                                    class="git-step-enter relative flex items-start gap-3 rounded-xl border p-3.5 transition-all duration-500"
                                    :style="`animation-delay: ${index * 60}ms`"
                                    :class="stepCardClass(step.status)"
                                >
                                    <div class="relative mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border"
                                         :class="stepIconWrapClass(step.status)">
                                        <template x-if="step.status === 'running'">
                                            <span class="h-2 w-2 rounded-full bg-cyan-400 animate-ping"></span>
                                        </template>
                                        <template x-if="step.status === 'completed'">
                                            <svg class="h-4 w-4 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        </template>
                                        <template x-if="step.status === 'failed'">
                                            <svg class="h-4 w-4 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </template>
                                        <template x-if="step.status === 'skipped'">
                                            <span class="text-slate-500 text-xs font-bold">—</span>
                                        </template>
                                        <template x-if="step.status === 'pending'">
                                            <span class="h-1.5 w-1.5 rounded-full bg-slate-600"></span>
                                        </template>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-sm text-slate-100" x-text="step.label"></p>
                                        <p x-show="step.message" x-transition class="text-xs text-slate-400 mt-1 font-mono leading-relaxed" x-text="step.message"></p>
                                    </div>
                                    <span class="text-[10px] font-mono uppercase tracking-wider shrink-0" :class="stepStatusTextClass(step.status)" x-text="step.status"></span>
                                </li>
                            </template>
                        </ol>
                    </template>

                    <div class="relative">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-cyan-400/70">Terminal output</p>
                            <span x-show="isActive" class="flex items-center gap-1.5 text-[10px] font-mono text-emerald-400/90">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                streaming
                            </span>
                        </div>
                        <div class="git-log-terminal relative rounded-xl border border-slate-700/80 bg-[#050508] overflow-hidden">
                            <div class="flex items-center gap-1.5 px-3 py-2 border-b border-slate-800 bg-slate-900/80">
                                <span class="h-2.5 w-2.5 rounded-full bg-red-500/80"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-amber-500/80"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-emerald-500/80"></span>
                                <span class="ml-2 text-[10px] font-mono text-slate-500">git-pull · service-{{ $service->id }}</span>
                            </div>
                            <pre
                                x-ref="logEl"
                                class="relative z-0 text-[11px] leading-relaxed font-mono text-cyan-100/90 p-4 h-64 overflow-auto whitespace-pre-wrap"
                                x-text="logOutput"
                            ></pre>
                        </div>
                    </div>

                    <p
                        x-show="pull?.error_message"
                        x-transition
                        class="relative text-sm text-red-300 bg-red-950/40 border border-red-500/30 rounded-xl px-4 py-3 font-mono"
                        x-text="pull?.error_message"
                    ></p>
                </div>
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
        logOutput: 'Awaiting sync command…',
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
            if (this.pollTimer) clearInterval(this.pollTimer);
            this.pollTimer = setInterval(() => this.refresh(), this.isActive ? 2500 : 15000);
        },

        progressPercent() {
            const steps = this.pull?.steps || [];
            if (!steps.length) return this.isActive ? 5 : 0;
            const weights = { completed: 1, skipped: 1, running: 0.5, failed: 0, pending: 0 };
            const score = steps.reduce((sum, s) => sum + (weights[s.status] ?? 0), 0);
            const pct = Math.round((score / steps.length) * 100);
            if (this.pull?.status === 'completed') return 100;
            if (this.pull?.status === 'failed') return Math.max(pct, 8);
            return Math.min(Math.max(pct, this.isActive ? 8 : 0), 99);
        },

        progressLabel() {
            if (!this.pull?.steps?.length) return 'Initializing pipeline…';
            const running = this.pull.steps.find(s => s.status === 'running');
            if (running) return `Running: ${running.label}`;
            if (this.pull.status === 'completed') return 'All stages complete';
            if (this.pull.status === 'failed') return 'Pipeline halted';
            return 'Queued stages';
        },

        statusBadgeClass() {
            const s = this.pull?.status;
            if (s === 'running' || s === 'pending') return 'border-cyan-400/40 bg-cyan-500/10 text-cyan-300';
            if (s === 'completed') return 'border-emerald-400/40 bg-emerald-500/10 text-emerald-300';
            if (s === 'failed') return 'border-red-400/40 bg-red-500/10 text-red-300';
            return 'border-slate-600 bg-slate-800 text-slate-400';
        },

        stepCardClass(status) {
            if (status === 'running') return 'git-step-running border-cyan-400/40 bg-cyan-500/5';
            if (status === 'completed') return 'border-emerald-500/25 bg-emerald-500/5';
            if (status === 'failed') return 'border-red-500/40 bg-red-500/5';
            if (status === 'skipped') return 'border-slate-700 bg-slate-900/30 opacity-60';
            return 'border-slate-800 bg-slate-900/20';
        },

        stepIconWrapClass(status) {
            if (status === 'running') return 'border-cyan-400/50 bg-cyan-500/10';
            if (status === 'completed') return 'border-emerald-500/40 bg-emerald-500/10';
            if (status === 'failed') return 'border-red-500/40 bg-red-500/10';
            return 'border-slate-700 bg-slate-800/50';
        },

        stepStatusTextClass(status) {
            if (status === 'running') return 'text-cyan-400';
            if (status === 'completed') return 'text-emerald-400/80';
            if (status === 'failed') return 'text-red-400';
            return 'text-slate-600';
        },

        scrollLogToBottom() {
            this.$nextTick(() => {
                const el = this.$refs.logEl;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },

        async refresh() {
            try {
                const response = await fetch(`{{ route('customer.services.container.git-repository.status', $service) }}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) return;

                const data = await response.json();
                const previousLog = this.logOutput;
                const previousStatus = this.pull?.status;

                this.repository = data.repository || this.repository;
                this.pull = data.pull || this.pull;
                this.logOutput = this.pull?.log || 'Awaiting sync command…';

                if (this.logOutput !== previousLog) this.scrollLogToBottom();

                const nowActive = this.isActive;
                const wasActive = previousStatus && ['pending', 'running'].includes(previousStatus);
                if (nowActive !== wasActive || (nowActive && this.pull?.status !== previousStatus)) {
                    this.schedulePoll();
                }
                if (this.pull?.status === 'completed') this.errorMessage = '';
            } catch (error) {
                console.error('Failed to refresh Git pull status', error);
            }
        },

        async startPull() {
            if (!this.canPull || this.pulling || this.isActive) return;

            if (!await window.appConfirm('Pull the latest code from Git into /app?', 'Pull from Git', 'Pull')) return;

            this.pulling = true;
            this.errorMessage = '';
            this.logOutput = '[init] Queuing Git sync pipeline…';

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
                this.logOutput = this.pull?.log || '[init] Pipeline started…';
                this.schedulePoll();
                this.scrollLogToBottom();
                await this.refresh();
            } catch (error) {
                this.errorMessage = 'Failed to start Git pull.';
            } finally {
                this.pulling = false;
            }
        },

        formatSyncedAt(iso) {
            if (!iso) return '';
            const date = new Date(iso);
            return Number.isNaN(date.getTime()) ? iso : date.toLocaleString();
        },
    };
}
</script>
@endpush
@endif
