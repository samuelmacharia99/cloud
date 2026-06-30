@props([
    'title',
    'description' => null,
])

<div>
    <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 dark:text-white">{{ $title }}</h1>
    @if ($description)
        <p class="text-slate-600 dark:text-slate-400 mt-1 text-sm sm:text-base">{{ $description }}</p>
    @endif
</div>
