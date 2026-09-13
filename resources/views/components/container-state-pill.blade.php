@props(['state', 'compact' => false, 'label' => null])

@php
    // Accepts a StackMemberState or a bare state string (running|degraded|stopped|...).
    $stateValue = $state instanceof \App\Services\Provisioning\StackMemberState ? $state->state : (string) $state;
    $stale = $state instanceof \App\Services\Provisioning\StackMemberState ? $state->stale : false;
    $status = $state instanceof \App\Services\Provisioning\StackMemberState ? $state->status : null;
    $checkedAt = $state instanceof \App\Services\Provisioning\StackMemberState ? $state->checkedAt : null;
    $label ??= $state instanceof \App\Services\Provisioning\StackMemberState
        ? $state->label()
        : ($stateValue === 'degraded' ? 'Degraded' : ucfirst($stateValue));

    $palette = match ($stateValue) {
        'running' => ['pill' => 'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-200', 'dot' => 'bg-emerald-500'],
        'restarting', 'paused', 'degraded' => ['pill' => 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-200', 'dot' => 'bg-amber-500'],
        'stopped', 'missing' => ['pill' => 'bg-red-100 dark:bg-red-950/60 text-red-700 dark:text-red-200', 'dot' => 'bg-red-500'],
        default => ['pill' => 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300', 'dot' => 'bg-slate-400'],
    };
    if ($stale) {
        $palette = ['pill' => 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400', 'dot' => 'border border-slate-400 bg-transparent'];
    }

    $title = trim(implode(' · ', array_filter([
        $status,
        $checkedAt ? 'checked '.$checkedAt->diffForHumans() : null,
        $stale ? 'may be out of date' : null,
    ])));
@endphp

<span {{ $attributes->merge(['class' => 'status-pill '.$palette['pill'].($compact ? ' px-2 py-0.5' : '')]) }} @if($title !== '') title="{{ $title }}" @endif data-container-state="{{ $stateValue }}">
    <span class="status-pill-dot {{ $palette['dot'] }}"></span>
    {{ $label }}
</span>
