@if (!empty($supportsPhpVersion) && $deployment && !empty($phpVersionPanel))
@php
    $panel = $phpVersionPanel;
    $current = $panel['current'] ?? null;
@endphp
<div class="space-y-6" x-data="{ chosen: @js($current ?? ''), busy: false }">
    <div>
        <h3 class="text-xl font-bold text-slate-900 dark:text-white">{{ $panel['label'] }}</h3>
        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 max-w-3xl">
            The application currently runs <strong data-php-version-current>{{ $panel['current_label'] }}</strong>{{ $current === null ? ' (the stack default)' : '' }}.
            Changing it rebuilds the runtime for that version and redeploys the stack. Files and the database are kept; the site is briefly unavailable while the container is recreated.
        </p>
        @if($panel['help'])
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $panel['help'] }}</p>
        @endif
    </div>

    @if ($panel['deploying'])
        <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-amber-800 dark:text-amber-200 text-sm">
            A deploy is running. The version can be changed once it finishes.
        </div>
    @endif

    <form method="POST" action="{{ container_route('php-version.update', $service) }}" class="space-y-4" @submit="busy = true" data-php-version-form>
        @csrf
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <label class="flex items-start gap-3 rounded-xl border p-4 cursor-pointer transition"
                   :class="chosen === '' ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-slate-200 dark:border-slate-700 hover:border-slate-300'">
                <input type="radio" name="version" value="" x-model="chosen" class="mt-1">
                <span>
                    <span class="block font-semibold text-slate-900 dark:text-white">Stack default ({{ $panel['default_label'] }})</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400 mt-0.5">Follows the platform's default for this stack.</span>
                </span>
            </label>
            @foreach ($panel['options'] as $option)
                <label class="flex items-start gap-3 rounded-xl border p-4 cursor-pointer transition"
                       :class="chosen === @js($option['value']) ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-slate-200 dark:border-slate-700 hover:border-slate-300'"
                       data-php-version-option="{{ $option['value'] }}">
                    <input type="radio" name="version" value="{{ $option['value'] }}" x-model="chosen" class="mt-1">
                    <span>
                        <span class="block font-semibold text-slate-900 dark:text-white">{{ $option['label'] }}
                            @if($current === $option['value'])
                                <span class="ml-1 rounded-md bg-emerald-100 dark:bg-emerald-900/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-emerald-700 dark:text-emerald-200">current</span>
                            @endif
                        </span>
                        @if(!empty($option['description']))
                            <span class="block text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ $option['description'] }}</span>
                        @endif
                    </span>
                </label>
            @endforeach
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition disabled:opacity-50"
                    :disabled="busy || {{ $panel['deploying'] ? 'true' : 'false' }} || chosen === @js($current ?? '')">
                <span x-show="!busy">Switch and redeploy</span>
                <span x-show="busy" x-cloak>Redeploying…</span>
            </button>
            <span class="text-xs text-slate-500 dark:text-slate-400">Takes a few minutes the first time a version is used on this host.</span>
        </div>
    </form>
</div>
@endif
