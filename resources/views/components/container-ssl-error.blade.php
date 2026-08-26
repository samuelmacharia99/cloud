@props([
    'title',
    'guidance',
    'details' => null,
])

<div {{ $attributes->class('rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 p-3') }}>
    <p class="text-sm font-semibold text-red-800 dark:text-red-200">{{ $title }}</p>
    <p class="mt-1 text-sm leading-relaxed text-red-700 dark:text-red-300">{{ $guidance }}</p>
    @if (filled($details))
        <details class="mt-2">
            <summary class="cursor-pointer text-xs font-semibold text-red-700 dark:text-red-200 hover:underline">
                Technical details
            </summary>
            <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap rounded-md border border-red-200 dark:border-red-800 bg-white/70 dark:bg-black/30 p-2 text-[11px] leading-relaxed text-red-800 dark:text-red-200 font-mono">{{ $details }}</pre>
        </details>
    @endif
</div>
