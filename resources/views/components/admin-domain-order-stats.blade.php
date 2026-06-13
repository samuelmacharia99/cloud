@props(['breakdown' => [], 'attention' => []])

@php
    $queued = (int) ($breakdown['queued'] ?? 0);
    $pushed = (int) ($breakdown['pushed'] ?? 0);
    $failed = (int) ($breakdown['failed'] ?? 0);
    $total = $queued + $pushed + $failed;
@endphp

@if($total > 0)
<div class="flex flex-wrap items-center gap-2 text-sm">
    <span class="text-slate-600 dark:text-slate-400 font-medium">Domain orders:</span>
    @if($queued > 0)
        <a href="{{ route('admin.domain-orders.index', ['status' => 'queued']) }}"
            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-amber-100 dark:bg-amber-950/50 text-amber-800 dark:text-amber-200 text-xs font-semibold hover:bg-amber-200 dark:hover:bg-amber-900/50 transition">
            {{ $queued }} queued
        </a>
    @endif
    @if($pushed > 0)
        <a href="{{ route('admin.domain-orders.index', ['status' => 'pushed']) }}"
            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-blue-100 dark:bg-blue-950/50 text-blue-800 dark:text-blue-200 text-xs font-semibold hover:bg-blue-200 dark:hover:bg-blue-900/50 transition">
            {{ $pushed }} pushed
        </a>
    @endif
    @if($failed > 0)
        <a href="{{ route('admin.domain-orders.index', ['status' => 'failed']) }}"
            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-red-100 dark:bg-red-950/50 text-red-800 dark:text-red-200 text-xs font-semibold hover:bg-red-200 dark:hover:bg-red-900/50 transition">
            {{ $failed }} failed
        </a>
    @endif
</div>
@endif
