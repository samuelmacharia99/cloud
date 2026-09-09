@php
    $consoleView = $migrationProgress ?? app(\App\Services\Provisioning\ContainerMigrationProgress::class)->operatorView($service);
@endphp
<style>
    @keyframes migration-bar-glow {
        0%, 100% { box-shadow: 0 0 12px rgba(45, 212, 191, 0.45); }
        50% { box-shadow: 0 0 18px rgba(56, 189, 248, 0.55); }
    }
    .migration-terminal::before {
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
</style>

<div
    class="rounded-2xl border border-slate-800 bg-slate-950 text-slate-100 overflow-hidden"
    x-data="containerMigrationConsole(@js($consoleView), @js(route('admin.services.container.migrate.progress', $service)))"
>
    <div class="px-4 sm:px-6 py-5 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.25em] text-teal-400/80">Migration console</p>
                <h3 class="text-base font-semibold tracking-tight">
                    Move to a new host
                    <span
                        class="ml-2 text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-full border"
                        :class="statusBadgeClass()"
                        x-text="view.status"
                    ></span>
                </h3>
                <p class="text-xs text-slate-400 mt-1 font-mono truncate" x-text="route()"></p>
            </div>
            <div class="text-right shrink-0">
                <p
                    class="text-3xl font-bold tabular-nums bg-gradient-to-r from-teal-300 via-sky-300 to-emerald-300 bg-clip-text text-transparent"
                    x-text="`${view.percent || 0}%`"
                ></p>
                <p class="text-[10px] uppercase tracking-widest text-slate-500">complete</p>
            </div>
        </div>

        <div class="h-2 rounded-full bg-slate-800 overflow-hidden border border-slate-700/50">
            <div
                class="h-full rounded-full transition-all duration-500 ease-out"
                :class="view.is_failed
                    ? 'bg-gradient-to-r from-red-500 to-orange-400'
                    : 'bg-gradient-to-r from-teal-400 via-sky-500 to-emerald-400' + (view.is_active ? ' animate-[migration-bar-glow_2s_ease-in-out_infinite]' : '')"
                :style="`width: ${view.percent || 0}%`"
            ></div>
        </div>

        <p class="text-sm text-slate-300" x-text="view.label"></p>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-1.5">
            <template x-for="step in view.steps" :key="step.key">
                <div class="flex items-center gap-2 text-[11px]">
                    <span class="w-4 text-center shrink-0" :class="stepIconClass(step)" x-text="stepIcon(step)"></span>
                    <span :class="stepTextClass(step)" x-text="step.label"></span>
                </div>
            </template>
        </div>

        <div class="migration-terminal relative rounded-xl border border-slate-700/80 bg-[#050508] overflow-hidden">
            <div class="flex items-center gap-1.5 px-3 py-2 border-b border-slate-800 bg-slate-900/80">
                <span class="h-2.5 w-2.5 rounded-full bg-red-500/80"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-amber-500/80"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-emerald-500/80"></span>
                <span class="ml-2 text-[10px] font-mono text-slate-500">migrate · service-{{ $service->id }}</span>
                <span x-show="view.is_active" class="ml-auto flex items-center gap-1.5 text-[10px] font-mono text-emerald-400/90">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    streaming
                </span>
            </div>
            <pre
                x-ref="logEl"
                class="relative z-0 text-[11px] leading-relaxed font-mono text-teal-100/90 p-4 h-72 overflow-auto whitespace-pre-wrap"
                x-text="view.log"
            ></pre>
        </div>

        <div x-show="view.is_failed" x-cloak class="rounded-xl border border-red-500/40 bg-red-500/10 p-3 space-y-1">
            <p class="text-sm text-red-200" x-text="view.error"></p>
            <p class="text-xs text-red-300/80" x-show="view.rolled_back">
                The app was restarted on the original host and no data was released. Fix the cause, then migrate again.
            </p>
        </div>

        <div x-show="view.is_done" x-cloak class="flex items-center justify-between gap-3 rounded-xl border border-emerald-500/40 bg-emerald-500/10 p-3">
            <p class="text-sm text-emerald-200" x-text="view.label"></p>
            <a href="{{ route('admin.services.show', $service) }}" class="text-xs font-semibold text-emerald-300 hover:underline shrink-0">
                Open service
            </a>
        </div>

        <p class="text-[11px] text-slate-500">
            The migration runs on a queue worker, so you can close this page and come back.
            It needs <code class="font-mono">php artisan queue:work</code> running with a timeout above
            <code class="font-mono">{{ (int) config('containers.migration.job_timeout_seconds', 14400) }}</code> seconds.
        </p>
    </div>
</div>

@push('scripts')
<script>
function containerMigrationConsole(initial, url) {
    return {
        view: initial,
        url,
        pollTimer: null,

        init() {
            this.scrollLog();
            this.announce();
            this.schedulePoll();
        },

        route() {
            if (!this.view.source_hostname && !this.view.target_hostname) {
                return 'No migration has run for this service yet.';
            }

            const reason = this.view.reason ? ` · ${this.view.reason}` : '';

            return `${this.view.source_hostname || 'unknown'} → ${this.view.target_hostname || 'unknown'}${reason}`;
        },

        statusBadgeClass() {
            if (this.view.is_active) return 'border-teal-400/40 bg-teal-500/10 text-teal-300';
            if (this.view.is_done) return 'border-emerald-400/40 bg-emerald-500/10 text-emerald-300';
            if (this.view.is_failed) return 'border-red-400/40 bg-red-500/10 text-red-300';

            return 'border-slate-600 bg-slate-800 text-slate-400';
        },

        stepIcon(step) {
            if (step.status === 'completed') return '✓';
            if (step.status === 'failed') return '✗';
            if (step.status === 'running') return '▸';

            return '·';
        },

        stepIconClass(step) {
            if (step.status === 'completed') return 'text-emerald-400';
            if (step.status === 'failed') return 'text-red-400';
            if (step.status === 'running') return 'text-teal-300 animate-pulse';

            return 'text-slate-600';
        },

        stepTextClass(step) {
            if (step.status === 'completed') return 'text-slate-400';
            if (step.status === 'failed') return 'text-red-300';
            if (step.status === 'running') return 'text-teal-200 font-medium';

            return 'text-slate-600';
        },

        // Lets the migration form disable itself while a move is in flight.
        announce() {
            window.dispatchEvent(new CustomEvent('container-migration-state', {
                detail: { active: Boolean(this.view.is_active) },
            }));
        },

        schedulePoll() {
            if (this.pollTimer) clearInterval(this.pollTimer);
            this.pollTimer = setInterval(() => this.refresh(), this.view.is_active ? 2000 : 15000);
        },

        scrollLog() {
            this.$nextTick(() => {
                const el = this.$refs.logEl;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },

        async refresh() {
            try {
                const response = await fetch(this.url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) return;

                const previousLog = this.view.log;
                const wasActive = this.view.is_active;
                this.view = await response.json();

                if (this.view.log !== previousLog) this.scrollLog();
                if (this.view.is_active !== wasActive) {
                    this.announce();
                    this.schedulePoll();
                }
            } catch (error) {
                console.error('Migration progress refresh failed', error);
            }
        },
    };
}
</script>
@endpush
