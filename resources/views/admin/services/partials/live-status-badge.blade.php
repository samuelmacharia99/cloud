@php
    $compact = $compact ?? false;
    $live = $service->live_status;
    $fullLabel = $service->live_status_label ?? ($live ? ucfirst($live) : 'Not checked');
    $label = $compact
        ? match ($live) {
            'active' => 'Running',
            'suspended' => 'Stopped',
            'terminated' => 'Gone',
            'provisioning' => 'Deploying',
            'pending' => 'Pending',
            'failed' => 'Failed',
            'unknown', 'unavailable' => 'Unknown',
            default => $live ? ucfirst($live) : '—',
        }
        : $fullLabel;
    $checkedAt = $service->live_status_checked_at;
    $source = $service->live_status_source;
    $mismatch = $service->live_status_mismatch;
    $supportsProbe = $service->supportsLiveStatusProbe();
    $title = $supportsProbe && $live
        ? trim($fullLabel.($checkedAt ? ' · '.$checkedAt->diffForHumans() : '').($source ? ' ('.$source.')' : ''))
        : null;

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
    <span class="text-xs text-slate-400">—</span>
@elseif (! $live)
    <span class="text-xs text-slate-500">Not checked</span>
@else
    <div @if($title) title="{{ $title }}" @endif class="{{ $compact ? 'inline-flex items-center gap-1' : 'space-y-1' }}">
        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold whitespace-nowrap {{ $color }}">
            {{ $label }}
        </span>
        @if ($mismatch)
            <span class="inline-flex items-center text-amber-600 dark:text-amber-400" title="Platform status differs from infrastructure">
                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
            </span>
        @endif
        @if (! $compact && $checkedAt)
            <p class="text-xs text-slate-500 dark:text-slate-400">
                {{ $source ? ucfirst($source).' · ' : '' }}{{ $checkedAt->diffForHumans() }}
            </p>
        @endif
    </div>
@endif
