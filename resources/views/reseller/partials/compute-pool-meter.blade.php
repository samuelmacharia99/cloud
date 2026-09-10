@php
    $computePool = $computePool ?? [];
    $cpu = $computePool['cpu'] ?? ['pool' => 0, 'used' => 0, 'remaining' => 0, 'over' => 0, 'percent' => null];
    $memory = $computePool['memory'] ?? ['pool' => 0, 'used' => 0, 'remaining' => 0, 'over' => 0, 'percent' => null];
    $compact = $compact ?? false;
    $barHeight = $compact ? 'h-1.5' : 'h-3';
    $bars = [
        [
            'label' => 'vCPU',
            'used' => number_format((float) $cpu['used'], 2),
            'pool' => rtrim(rtrim(number_format((float) $cpu['pool'], 2), '0'), '.'),
            'percent' => $cpu['percent'],
            'over' => (float) $cpu['over'] > 0 ? number_format((float) $cpu['over'], 2).' vCPU over the pool.' : null,
            'remaining' => number_format((float) $cpu['remaining'], 2).' vCPU remaining.',
        ],
        [
            'label' => 'RAM',
            'used' => number_format(((int) $memory['used']) / 1024, 1),
            'pool' => number_format(((int) $memory['pool']) / 1024, 1),
            'percent' => $memory['percent'],
            'over' => (int) $memory['over'] > 0 ? number_format(((int) $memory['over']) / 1024, 1).' GB over the pool.' : null,
            'remaining' => number_format(((int) $memory['remaining']) / 1024, 1).' GB remaining.',
        ],
    ];
@endphp

@if (($cpu['pool'] ?? 0) > 0 || ($memory['pool'] ?? 0) > 0)
    <div class="space-y-3">
        @foreach ($bars as $bar)
            @continue($bar['percent'] === null)
            <div>
                <div class="flex justify-between {{ $compact ? 'mb-1 text-xs' : 'items-center mb-2' }}">
                    <span class="{{ $compact ? 'text-slate-500' : 'font-medium text-slate-900 dark:text-white' }}">{{ $bar['label'] }} pool</span>
                    <span class="{{ $compact ? '' : 'text-sm text-slate-600 dark:text-slate-400' }}">
                        {{ $bar['used'] }} / {{ $bar['pool'] }}{{ $bar['label'] === 'RAM' ? ' GB' : '' }}
                    </span>
                </div>
                <div class="w-full {{ $barHeight }} bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                    <div class="{{ $bar['percent'] >= 90 ? 'bg-amber-500' : 'bg-emerald-500' }} {{ $barHeight }} rounded-full" style="width: {{ min(100, $bar['percent']) }}%"></div>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $bar['over'] ?? $bar['remaining'] }}</p>
            </div>
        @endforeach
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Application hosting only, allocated at each service's last deploy. Shared hosting has no CPU or memory allocation.
        </p>
    </div>
@endif
