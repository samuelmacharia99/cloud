@if (session('success') || session('error') || session('warning') || session('info'))
<div class="px-4 sm:px-6 pt-4 space-y-3">
    @if (session('success'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
        x-transition:leave="transition ease-in duration-300"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-2"
        class="flex items-start gap-3 px-4 py-3.5 rounded-xl border bg-emerald-50/90 dark:bg-emerald-950/50 border-emerald-200/80 dark:border-emerald-800/80 text-emerald-800 dark:text-emerald-200 shadow-sm">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-900/60">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </span>
        <p class="text-sm font-medium flex-1 pt-1">{{ session('success') }}</p>
        <button type="button" @click="show = false" class="btn-ghost btn-sm !p-1.5 text-emerald-600 dark:text-emerald-400" aria-label="Dismiss">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
    @endif

    @if (session('error'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 7000)"
        x-transition:leave="transition ease-in duration-300"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-2"
        class="flex items-start gap-3 px-4 py-3.5 rounded-xl border bg-red-50/90 dark:bg-red-950/50 border-red-200/80 dark:border-red-800/80 text-red-800 dark:text-red-200 shadow-sm">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-red-100 dark:bg-red-900/60">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </span>
        <p class="text-sm font-medium flex-1 pt-1 whitespace-pre-wrap">{{ session('error') }}</p>
        <button type="button" @click="show = false" class="btn-ghost btn-sm !p-1.5 text-red-600 dark:text-red-400" aria-label="Dismiss">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
    @endif

    @if (session('warning'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
        class="flex items-start gap-3 px-4 py-3.5 rounded-xl border bg-amber-50/90 dark:bg-amber-950/50 border-amber-200/80 dark:border-amber-800/80 text-amber-800 dark:text-amber-200 shadow-sm">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900/60">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </span>
        <p class="text-sm font-medium flex-1 pt-1">{{ session('warning') }}</p>
        <button type="button" @click="show = false" class="btn-ghost btn-sm !p-1.5" aria-label="Dismiss">✕</button>
    </div>
    @endif

    @if (session('info'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
        class="flex items-start gap-3 px-4 py-3.5 rounded-xl border bg-brand-50/90 dark:bg-brand-950/50 border-brand-200/80 dark:border-brand-800/80 text-brand-800 dark:text-brand-200 shadow-sm">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-100 dark:bg-brand-900/60">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </span>
        <p class="text-sm font-medium flex-1 pt-1">{{ session('info') }}</p>
        <button type="button" @click="show = false" class="btn-ghost btn-sm !p-1.5" aria-label="Dismiss">✕</button>
    </div>
    @endif
</div>
@endif
