@props(['title', 'description' => null, 'href' => null, 'action_text' => 'View all'])

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
    <div class="p-6 border-b border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $title }}</h2>
                @if($description)
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">{{ $description }}</p>
                @endif
            </div>
            @if($href)
                <a href="{{ $href }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">
                    {{ $action_text }} →
                </a>
            @endif
        </div>
    </div>
    <div>
        {{ $slot }}
    </div>
</div>
