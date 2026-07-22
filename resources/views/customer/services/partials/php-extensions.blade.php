@if (!empty($supportsPhpExtensions) && $deployment && !empty($phpExtensionsPanel))
<div class="space-y-8">
    <div>
        <h3 class="text-xl font-bold text-slate-900 dark:text-white">PHP Extensions</h3>
        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 max-w-3xl">
            Enable optional PHP extensions for your application. Built-in extensions ship with the Talksasa PHP runtime.
            Changes apply immediately while the app is running; restart the app if it still reports a missing extension.
        </p>
    </div>

    @if (! $phpExtensionsPanel['container_running'])
        <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-amber-800 dark:text-amber-200 text-sm">
            Start the app to install extensions and verify which modules are loaded.
        </div>
    @endif

  <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/40 p-5">
        <h4 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Included with your PHP runtime</h4>
        <div class="flex flex-wrap gap-2">
            @foreach ($phpExtensionsPanel['builtin'] as $extension)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border
                    {{ $extension['installed'] ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $extension['installed'] ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                    {{ $extension['label'] }}
                </span>
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ route('customer.services.container.php-extensions.update', $service) }}" class="space-y-4">
        @csrf

        <div class="rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800">
                <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Optional extensions</h4>
            </div>

            <div class="divide-y divide-slate-200 dark:divide-slate-700">
                @forelse ($phpExtensionsPanel['optional'] as $extension)
                    <label class="flex items-start gap-4 p-5 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/80 transition cursor-pointer">
                        <input
                            type="checkbox"
                            name="extensions[]"
                            value="{{ $extension['key'] }}"
                            @checked($extension['enabled'])
                            @disabled(! $phpExtensionsPanel['container_running'])
                            class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50"
                        >
                        <span class="flex-1 min-w-0">
                            <span class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium text-slate-900 dark:text-white">{{ $extension['label'] }}</span>
                                <code class="text-xs font-mono text-slate-500 dark:text-slate-400">{{ $extension['key'] }}</code>
                                @if ($extension['installed'])
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">
                                        Loaded
                                    </span>
                                @elseif ($extension['enabled'])
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">
                                        Pending install
                                    </span>
                                @endif
                            </span>
                            @if ($extension['description'] !== '')
                                <span class="block text-sm text-slate-600 dark:text-slate-400 mt-1">{{ $extension['description'] }}</span>
                            @endif
                        </span>
                    </label>
                @empty
                    <div class="p-5 text-sm text-slate-600 dark:text-slate-400">No optional extensions are configured.</div>
                @endforelse
            </div>
        </div>

        <div class="flex items-center justify-between gap-4 flex-wrap">
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Unchecking an extension updates your saved preferences but does not remove it from the base runtime image.
            </p>
            <button
                type="submit"
                @disabled(! $phpExtensionsPanel['container_running'])
                class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 disabled:dark:bg-slate-700 disabled:cursor-not-allowed text-white rounded-lg font-medium transition"
            >
                Save extensions
            </button>
        </div>
    </form>
</div>
@endif
