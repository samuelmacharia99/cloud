@props(['title', 'description' => null, 'href' => null, 'action_text' => 'View all'])

<div {{ $attributes->merge(['class' => 'ui-card overflow-hidden']) }}>
    <div class="ui-card-header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-base sm:text-lg font-semibold text-slate-900 dark:text-white">{{ $title }}</h2>
                @if($description)
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">{{ $description }}</p>
                @endif
            </div>
            @if($href)
                <a href="{{ $href }}" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 dark:text-brand-400 hover:text-brand-700 dark:hover:text-brand-300 transition-colors shrink-0">
                    {{ $action_text }}
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            @endif
        </div>
    </div>
    <div>
        {{ $slot }}
    </div>
</div>
