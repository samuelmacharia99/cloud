@php
    /** @var \App\Services\Customer\DeployTarget $target */
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
@endphp
<article class="ui-card flex flex-col rounded-2xl p-5 {{ $target->hasRoom ? '' : 'opacity-80' }}" data-deploy-target="{{ $target->project->id }}" data-has-room="{{ $target->hasRoom ? '1' : '0' }}">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 class="truncate text-base font-semibold text-ink-950 dark:text-white">{{ $target->project->name }}</h3>
            <p class="mt-0.5 text-xs text-ink-500 dark:text-ink-400">{{ $target->planName }} · {{ $target->serviceCount }} {{ Str::plural('service', $target->serviceCount) }}</p>
        </div>
        @if($target->hasRoom)
            <span class="status-pill bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-200"><span class="status-pill-dot bg-emerald-500"></span>Room</span>
        @else
            <span class="status-pill bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-200"><span class="status-pill-dot bg-amber-500"></span>Full</span>
        @endif
    </div>

    <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
        <div class="rounded-xl bg-ink-50/80 dark:bg-white/5 px-3 py-2">
            <dt class="text-ink-500 dark:text-ink-400">CPU unallocated</dt>
            <dd class="font-semibold text-ink-900 dark:text-white">{{ $target->remainingCpuPercent() }}%</dd>
        </div>
        <div class="rounded-xl bg-ink-50/80 dark:bg-white/5 px-3 py-2">
            <dt class="text-ink-500 dark:text-ink-400">RAM unallocated</dt>
            <dd class="font-semibold text-ink-900 dark:text-white">{{ $target->remainingMemoryPercent() }}%</dd>
        </div>
    </dl>

    <p class="mt-3 text-xs text-ink-500 dark:text-ink-400">
        @if($target->hasRoom)
            {{ $target->eligibleStackCount }} {{ Str::plural('stack', $target->eligibleStackCount) }} can run here. Deploys now, nothing to pay.
        @else
            {{ $target->fullReason }}
        @endif
    </p>

    <div class="mt-4 flex gap-2">
        @if($target->hasRoom)
            <a href="{{ route('customer.projects.deploy', $target->project) }}" class="btn-primary btn-sm flex-1 text-center">Deploy here</a>
        @else
            <span class="btn-secondary btn-sm flex-1 text-center opacity-60 cursor-not-allowed" aria-disabled="true">This plan is full</span>
            <a href="{{ route('customer.services.upgrade', $target->anchor) }}" class="btn-primary btn-sm">Upgrade</a>
        @endif
    </div>
</article>
