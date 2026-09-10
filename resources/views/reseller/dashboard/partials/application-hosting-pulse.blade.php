@php
    $running = max(0, $applicationCount - (int) ($health['containers_down'] ?? 0));
    $down = (int) ($health['containers_down'] ?? 0);
    $failed = (int) ($health['failed_services'] ?? 0);
@endphp

<div class="space-y-3">
    <div>
        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Application hosting</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
            Containers your customers run on Talksasa. Customers deploy and restart their own applications; you can run a diagnosis from any service.
        </p>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
        <div class="rounded-lg border border-slate-200 dark:border-slate-700 p-3">
            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Applications</p>
            <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($applicationCount) }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 dark:border-slate-700 p-3">
            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Running</p>
            <p class="mt-1 text-lg font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($running) }}</p>
        </div>
        <a href="{{ route('reseller.services.index', ['status' => 'active']) }}"
           class="rounded-lg border p-3 transition {{ $down > 0 ? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/30' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/60' }}">
            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Not running</p>
            <p class="mt-1 text-lg font-semibold {{ $down > 0 ? 'text-red-700 dark:text-red-300' : 'text-slate-900 dark:text-white' }}">{{ number_format($down) }}</p>
        </a>
        <a href="{{ route('reseller.services.index', ['status' => 'failed']) }}"
           class="rounded-lg border p-3 transition {{ $failed > 0 ? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/30' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/60' }}">
            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Failed</p>
            <p class="mt-1 text-lg font-semibold {{ $failed > 0 ? 'text-red-700 dark:text-red-300' : 'text-slate-900 dark:text-white' }}">{{ number_format($failed) }}</p>
        </a>
    </div>

    @if (($diskPoolGb ?? 0) > 0)
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Applications are using {{ number_format((float) $containerDiskGb, 1) }} GB of your {{ number_format((int) $diskPoolGb) }} GB pool.
        </p>
    @endif
</div>
