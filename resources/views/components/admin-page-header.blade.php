@props(['title', 'description' => null])

<div class="mb-6 sm:mb-8">
    <h1 class="page-title text-2xl sm:text-3xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $title }}</h1>
    @if($description)
        <p class="mt-1.5 text-sm sm:text-base text-slate-600 dark:text-slate-400 max-w-2xl">{{ $description }}</p>
    @endif
</div>
