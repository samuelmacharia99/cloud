@php
    $diskPool = $diskPool ?? [];
    $poolGb = (int) ($diskPool['pool_gb'] ?? 0);
    $usedGb = (float) ($diskPool['used_gb'] ?? 0);
    $remainingGb = (float) ($diskPool['remaining_gb'] ?? 0);
    $overGb = (float) ($diskPool['over_gb'] ?? 0);
    $percent = $diskPool['percent'] ?? null;
    $directAdminGb = (float) ($diskPool['directadmin_gb'] ?? 0);
    $containerGb = (float) ($diskPool['container_gb'] ?? 0);
    $compact = $compact ?? false;
    $barHeight = $compact ? 'h-1.5' : 'h-3';
    $barColor = ($percent ?? 0) >= 90 ? 'bg-amber-500' : 'bg-emerald-500';
@endphp

@if ($poolGb > 0)
    <div>
        <div class="flex justify-between {{ $compact ? 'mb-1 text-xs' : 'items-center mb-2' }}">
            <span class="{{ $compact ? 'text-slate-500' : 'font-medium text-slate-900 dark:text-white' }}">Disk pool</span>
            <span class="{{ $compact ? '' : 'text-sm text-slate-600 dark:text-slate-400' }}">
                {{ number_format($usedGb, $usedGb >= 10 ? 1 : 2) }} / {{ number_format($poolGb) }} GB
            </span>
        </div>
        <div class="w-full {{ $barHeight }} bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
            <div class="{{ $barColor }} {{ $barHeight }} rounded-full" style="width: {{ min(100, $percent ?? 0) }}%"></div>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
            @if ($overGb > 0)
                {{ number_format($overGb, 1) }} GB over the package pool.
            @else
                {{ number_format($remainingGb, $remainingGb >= 10 ? 1 : 2) }} GB remaining.
            @endif
            DirectAdmin {{ number_format($directAdminGb, 1) }} GB · Containers {{ number_format($containerGb, 1) }} GB
        </p>
    </div>
@endif
