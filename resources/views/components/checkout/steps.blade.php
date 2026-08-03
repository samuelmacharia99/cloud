@props([
    'current' => 'review', // cart|review|pay
])

@php
    $steps = [
        'cart' => 'Cart',
        'review' => 'Review',
        'pay' => 'Pay',
    ];
    $order = array_keys($steps);
    $currentIndex = array_search($current, $order, true);
    if ($currentIndex === false) {
        $currentIndex = 1;
    }
@endphp

<nav aria-label="Checkout progress" {{ $attributes->merge(['class' => 'flex items-center gap-2 sm:gap-3']) }}>
    @foreach ($steps as $key => $label)
        @php
            $index = array_search($key, $order, true);
            $done = $index < $currentIndex;
            $active = $index === $currentIndex;
        @endphp
        <div class="flex items-center gap-2 sm:gap-3 {{ $loop->last ? '' : 'flex-1' }}">
            <div class="flex items-center gap-2 min-w-0">
                <span @class([
                    'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                    'bg-blue-600 text-white' => $active || $done,
                    'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' => ! $active && ! $done,
                ])>
                    @if ($done)
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                        </svg>
                    @else
                        {{ $index + 1 }}
                    @endif
                </span>
                <span @class([
                    'text-sm font-medium truncate',
                    'text-slate-900 dark:text-white' => $active || $done,
                    'text-slate-500 dark:text-slate-400' => ! $active && ! $done,
                ])>{{ $label }}</span>
            </div>
            @unless ($loop->last)
                <div @class([
                    'hidden sm:block h-px flex-1',
                    'bg-blue-600' => $done,
                    'bg-slate-200 dark:bg-slate-700' => ! $done,
                ])></div>
            @endunless
        </div>
    @endforeach
</nav>
