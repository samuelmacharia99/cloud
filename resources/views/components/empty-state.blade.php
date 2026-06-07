@props([
    'title',
    'description' => null,
    'actionLabel' => null,
    'actionHref' => null,
    'icon' => null,
])

<div {{ $attributes->merge(['class' => 'ui-card ui-card-body text-center py-12 sm:py-16']) }}>
    @if($icon)
        <div class="mx-auto w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 dark:text-slate-500 mb-4">
            {!! $icon !!}
        </div>
    @else
        <div class="mx-auto w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
            <svg class="w-7 h-7 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
            </svg>
        </div>
    @endif

    <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>

    @if($description)
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 max-w-md mx-auto">{{ $description }}</p>
    @endif

    @if($actionLabel && $actionHref)
        <a href="{{ $actionHref }}" class="btn-primary mt-6">{{ $actionLabel }}</a>
    @endif

    @if(isset($slot) && ! $slot->isEmpty())
        <div class="mt-6">{{ $slot }}</div>
    @endif
</div>
