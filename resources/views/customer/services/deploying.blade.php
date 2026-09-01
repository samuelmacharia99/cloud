@extends('layouts.customer')

@section('title', 'Deploying '.$service->name)

@section('content')
@php
    $progress = $progress ?? [];
@endphp
<style>
    @keyframes deploy-shimmer {
        0% { transform: translateX(-120%) skewX(-12deg); }
        100% { transform: translateX(220%) skewX(-12deg); }
    }
    @keyframes deploy-bar-glow {
        0%, 100% { box-shadow: 0 0 12px rgba(45, 212, 191, 0.45); }
        50% { box-shadow: 0 0 18px rgba(56, 189, 248, 0.55); }
    }
    .deploy-terminal::before {
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
    class="max-w-3xl mx-auto space-y-6"
    x-data="deployConsole(@js($progress), @js(route('customer.services.deploying.status', $service)))"
    x-init="start()"
>
    <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-ink-500 dark:text-ink-400 mb-2">Deploy</p>
        <h1 class="font-display text-3xl text-ink-950 dark:text-white leading-tight">{{ $service->name }}</h1>
        <p class="text-ink-600 dark:text-ink-400 mt-2" x-text="view.headline"></p>
    </div>

    <div class="rounded-3xl border border-ink-200 dark:border-ink-800 bg-ink-950 text-slate-100 overflow-hidden shadow-xl">
        <div class="px-5 sm:px-6 py-5 space-y-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.25em] text-teal-400/80">Live console</p>
                    <h2 class="text-lg font-semibold tracking-tight mt-1">
                        <span x-text="view.stack || 'Application'"></span>
                        <span
                            class="ml-2 text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-full border"
                            :class="statusBadgeClass()"
                            x-text="view.status || 'queued'"
                        ></span>
                    </h2>
                </div>
                <div class="text-right">
                    <p class="text-3xl font-bold tabular-nums bg-gradient-to-r from-teal-300 via-sky-300 to-emerald-300 bg-clip-text text-transparent" x-text="`${view.percent || 0}%`"></p>
                    <p class="text-[10px] uppercase tracking-widest text-slate-500">complete</p>
                </div>
            </div>

            <div class="h-2 rounded-full bg-slate-800 overflow-hidden border border-slate-700/50">
                <div
                    class="h-full rounded-full bg-gradient-to-r from-teal-400 via-sky-500 to-emerald-400 transition-all duration-500 ease-out"
                    :class="view.is_active ? 'animate-[deploy-bar-glow_2s_ease-in-out_infinite]' : ''"
                    :style="`width: ${view.percent || 0}%`"
                ></div>
            </div>
            <div x-show="view.is_active" class="relative h-0.5 overflow-hidden">
                <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/30 to-transparent animate-[deploy-shimmer_2s_ease-in-out_infinite]"></div>
            </div>

            <ul class="space-y-2">
                <template x-for="step in (view.steps || [])" :key="step.key">
                    <li class="flex items-center gap-2.5 text-sm text-slate-200">
                        <span class="w-5 text-center" x-text="stepIcon(step.status)"></span>
                        <span x-text="step.label"></span>
                    </li>
                </template>
            </ul>

            <div class="deploy-terminal relative rounded-xl border border-slate-700/80 bg-[#050508] overflow-hidden">
                <div class="flex items-center gap-1.5 px-3 py-2 border-b border-slate-800 bg-slate-900/80">
                    <span class="h-2.5 w-2.5 rounded-full bg-red-500/80"></span>
                    <span class="h-2.5 w-2.5 rounded-full bg-amber-500/80"></span>
                    <span class="h-2.5 w-2.5 rounded-full bg-emerald-500/80"></span>
                    <span class="ml-2 text-[10px] font-mono text-slate-500">deploy · service-{{ $service->id }}</span>
                    <span x-show="view.is_active" class="ml-auto text-[10px] font-mono text-emerald-400/90 flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        live
                    </span>
                </div>
                <pre
                    x-ref="logEl"
                    class="relative z-0 text-[11px] leading-relaxed font-mono text-teal-100/90 p-4 h-72 overflow-auto whitespace-pre-wrap"
                    x-text="view.log"
                ></pre>
            </div>

            <div class="flex flex-col sm:flex-row gap-3">
                <form
                    x-show="view.can_retry && !view.is_active"
                    x-cloak
                    method="POST"
                    action="{{ route('customer.services.deploying.retry', $service) }}"
                >
                    @csrf
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-500 hover:bg-teal-400 text-ink-950 text-sm font-semibold">
                        Retry deploy
                    </button>
                </form>
                @if($service->project_id)
                    <a href="{{ route('customer.projects.show', $service->project_id) }}" class="px-4 py-2.5 rounded-xl border border-slate-600 text-slate-200 text-sm font-semibold text-center hover:bg-slate-800">
                        Back to project
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function deployConsole(initial, statusUrl) {
    return {
        view: initial || {},
        statusUrl,
        timer: null,
        start() {
            this.scrollLog();
            if (this.view.redirect) {
                window.location.href = this.view.redirect;
                return;
            }
            if (this.view.is_active) {
                this.timer = setInterval(() => this.refresh(), 2500);
            }
        },
        stop() {
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
        },
        async refresh() {
            try {
                const response = await fetch(this.statusUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) {
                    return;
                }
                this.view = await response.json();
                this.$nextTick(() => this.scrollLog());
                if (this.view.redirect) {
                    this.stop();
                    window.location.href = this.view.redirect;
                    return;
                }
                if (!this.view.is_active) {
                    this.stop();
                }
            } catch (error) {
                console.error('Deploy status refresh failed', error);
            }
        },
        scrollLog() {
            if (this.$refs.logEl) {
                this.$refs.logEl.scrollTop = this.$refs.logEl.scrollHeight;
            }
        },
        stepIcon(status) {
            return ({ completed: '✅', running: '⏳', failed: '❌', pending: '⬜' })[status] || '⬜';
        },
        statusBadgeClass() {
            if (this.view.is_ready) {
                return 'border-emerald-400/40 text-emerald-300';
            }
            if (this.view.is_failed) {
                return 'border-red-400/40 text-red-300';
            }
            return 'border-sky-400/40 text-sky-300';
        },
    };
}
</script>
@endpush
@endsection
