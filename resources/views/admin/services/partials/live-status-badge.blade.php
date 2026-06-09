@php
    $live = $service->live_status;
    $label = $service->live_status_label ?? ($live ? ucfirst($live) : 'Not checked');
    $checkedAt = $service->live_status_checked_at;
    $source = $service->live_status_source;
    $mismatch = $service->live_status_mismatch;
    $supportsProbe = $service->supportsLiveStatusProbe();

    $color = match ($live) {
        'active' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300',
        'suspended' => 'bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300',
        'terminated' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
        'provisioning', 'pending' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300',
        'failed' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
        'unknown', 'unavailable' => 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400',
        default => 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400',
    };
@endphp

@if (! $supportsProbe)
    <span class="text-xs text-slate-400">N/A</span>
@elseif (! $live)
    <span class="text-xs text-slate-500">Not checked</span>
@else
    <div class="space-y-1">
        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $color }}">
            {{ $label }}
        </span>
        @if ($mismatch)
            <p class="text-xs font-medium text-amber-600 dark:text-amber-400">Drift detected</p>
        @endif
        @if ($checkedAt)
            <p class="text-xs text-slate-500 dark:text-slate-400">
                {{ $source ? ucfirst($source).' · ' : '' }}{{ $checkedAt->diffForHumans() }}
            </p>
        @endif
    </div>
@endif
