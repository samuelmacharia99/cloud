@php
    $mailPullView = app(\App\Services\Provisioning\DirectAdminMailPullProgress::class)->operatorView($service);
    $showOperatorConsole = ! empty($daConvert['status'])
        || in_array($mailPullView['status'] ?? '', ['pending', 'running', 'completed', 'failed'], true)
        || ! empty($service->service_meta['mailcow_migration']['email_service_id'])
        || ! empty($service->service_meta['da_legacy']['email_service_id']);
@endphp
@if ($showOperatorConsole)
<style>
    @keyframes mail-pull-shimmer {
        0% { transform: translateX(-120%) skewX(-12deg); }
        100% { transform: translateX(220%) skewX(-12deg); }
    }
    @keyframes mail-pull-bar-glow {
        0%, 100% { box-shadow: 0 0 12px rgba(45, 212, 191, 0.45); }
        50% { box-shadow: 0 0 18px rgba(56, 189, 248, 0.55); }
    }
    .mail-pull-terminal::before {
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
    x-data="mailPullConsole(@js($mailPullView))"
>
    <div class="px-4 sm:px-5 py-4 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.25em] text-teal-400/80">Operator console</p>
                <h3 class="text-base font-semibold tracking-tight">
                    DA convert / Mail pull
                    <span class="ml-2 text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-full border"
                          :class="statusBadgeClass()"
                          x-text="view.status || 'idle'"></span>
                </h3>
                <p class="text-xs text-slate-400 mt-1 font-mono" x-text="view.label"></p>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-right">
                    <p class="text-3xl font-bold tabular-nums bg-gradient-to-r from-teal-300 via-sky-300 to-emerald-300 bg-clip-text text-transparent" x-text="`${view.percent || 0}%`"></p>
                    <p class="text-[10px] uppercase tracking-widest text-slate-500">complete</p>
                </div>
                <button
                    type="button"
                    x-show="view.can_retry && !view.is_active"
                    x-cloak
                    @click="startRetry()"
                    :disabled="starting"
                    class="px-3 py-1.5 bg-teal-600 hover:bg-teal-500 text-white text-xs font-semibold rounded-lg transition disabled:opacity-50"
                >
                    <span x-text="starting ? 'Starting…' : 'Retry mail pull'"></span>
                </button>
            </div>
        </div>

        <div class="h-2 rounded-full bg-slate-800 overflow-hidden border border-slate-700/50">
            <div
                class="h-full rounded-full bg-gradient-to-r from-teal-400 via-sky-500 to-emerald-400 transition-all duration-500 ease-out"
                :class="view.is_active ? 'animate-[mail-pull-bar-glow_2s_ease-in-out_infinite]' : ''"
                :style="`width: ${view.percent || 0}%`"
            ></div>
        </div>
        <div x-show="view.is_active" class="relative h-0.5 overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/30 to-transparent animate-[mail-pull-shimmer_2s_ease-in-out_infinite]"></div>
        </div>

        <div class="flex flex-wrap gap-3 text-[11px] font-mono text-slate-400">
            <span x-show="view.mailbox_total">Mailbox <span class="text-teal-300" x-text="`${view.mailbox_index || 0}/${view.mailbox_total}`"></span></span>
            <span x-show="view.bytes_total" x-text="byteLabel()"></span>
            <span x-show="view.phase && view.phase !== 'idle'" class="uppercase tracking-wider text-sky-300" x-text="view.phase"></span>
            <span x-show="view.is_active" class="flex items-center gap-1.5 text-emerald-400">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                live
            </span>
        </div>

        @if (!empty($daConvert['error']))
            <p class="text-sm text-red-300">{{ $daConvert['error'] }}</p>
        @endif

        <div class="mail-pull-terminal relative rounded-xl border border-slate-700/80 bg-[#050508] overflow-hidden">
            <div class="flex items-center gap-1.5 px-3 py-2 border-b border-slate-800 bg-slate-900/80">
                <span class="h-2.5 w-2.5 rounded-full bg-red-500/80"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-amber-500/80"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-emerald-500/80"></span>
                <span class="ml-2 text-[10px] font-mono text-slate-500">mail-pull · service-{{ $service->id }}</span>
                <span x-show="view.is_active" class="ml-auto text-[10px] font-mono text-emerald-400/90">streaming</span>
            </div>
            <pre
                x-ref="logEl"
                class="relative z-0 text-[11px] leading-relaxed font-mono text-teal-100/90 p-4 h-72 overflow-auto whitespace-pre-wrap"
                x-text="view.log"
            ></pre>
        </div>

        <p class="text-[11px] text-slate-500">
            DirectAdmin mail is not deleted. Leave MX on DirectAdmin until inboxes have mail.
            @if (in_array($daConvert['status'] ?? '', ['queued', 'running'], true))
                Prefer <code class="font-mono">QUEUE_CONNECTION=database</code> with
                <code class="font-mono">php artisan queue:work --timeout=2400</code>.
                @if (!empty($daConvert['heartbeat_at']))
                    Heartbeat: {{ $daConvert['heartbeat_at'] }}
                @endif
            @endif
        </p>

        @if ($canRevertDaConvert)
            <form method="POST" action="{{ route('admin.services.revert-from-container', $service) }}" data-confirm="{{ in_array($daConvert['status'] ?? '', ['queued', 'running'], true) ? 'Convert looks stuck. Force revert to DirectAdmin? Stop any queue worker first if it is still processing this job. Delete leftover containers on the node manually.' : 'Restore this service to DirectAdmin? You must delete any leftover container on the node yourself.' }}" data-confirm-title="Revert to DirectAdmin">
                @csrf
                <button type="submit" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-white text-xs font-medium rounded-lg transition">
                    {{ in_array($daConvert['status'] ?? '', ['queued', 'running'], true) ? 'Force revert (stuck convert)' : 'Revert to DirectAdmin' }}
                </button>
            </form>
        @endif
        @if (($daConvert['status'] ?? '') === 'reverted' && !empty($daConvert['manual_container_cleanup']))
            <p class="text-xs font-mono text-slate-500">Manual cleanup: /opt/talksasa/containers/{{ $daConvert['manual_container_cleanup'] }}</p>
        @endif
    </div>
</div>
@endif
