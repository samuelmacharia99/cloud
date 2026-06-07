@props(['title', 'description' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between']) }}>
    <div class="min-w-0">
        <h1 class="page-title text-2xl sm:text-3xl font-bold tracking-tight text-slate-900 dark:text-white text-balance">
            {{ $title }}
        </h1>
        @if($description)
            <p class="mt-1.5 text-sm sm:text-base text-slate-600 dark:text-slate-400 text-balance max-w-2xl">
                {{ $description }}
            </p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2 shrink-0">
            {{ $actions }}
        </div>
    @endisset
</div>
