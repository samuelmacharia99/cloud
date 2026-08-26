@if (!empty($supportsPhpExtensions) && $deployment && !empty($phpExtensionsPanel))
<div
    class="space-y-8"
    x-data="phpExtensionsPanel()"
>
    <div>
        <h3 class="text-xl font-bold text-slate-900 dark:text-white">PHP Extensions</h3>
        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 max-w-3xl">
            Toggle an optional PHP extension to install it on the running container immediately.
            Built-in extensions ship with the Talksasa PHP runtime.
            Restart the app if it still reports a missing extension after install.
        </p>
    </div>

    @if (! $phpExtensionsPanel['container_running'])
        <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-amber-800 dark:text-amber-200 text-sm">
            Start the app to install extensions and verify which modules are loaded.
        </div>
    @endif

    <div
        x-show="successMessage"
        x-cloak
        class="rounded-xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 p-4 text-emerald-800 dark:text-emerald-200 text-sm"
        x-text="successMessage"
    ></div>

    <div
        x-show="errorMessage"
        x-cloak
        class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4 text-red-800 dark:text-red-200 text-sm"
        x-text="errorMessage"
    ></div>

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

    <div class="rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800">
            <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Optional extensions</h4>
        </div>

        <div class="divide-y divide-slate-200 dark:divide-slate-700">
            <template x-for="extension in extensions" :key="extension.key">
                <div class="flex items-start gap-4 p-5 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/80 transition">
                    <button
                        type="button"
                        role="switch"
                        :id="'php-ext-' + extension.key"
                        :aria-checked="extension.enabled ? 'true' : 'false'"
                        :aria-label="'Toggle ' + extension.label"
                        :disabled="!containerRunning || busyKey !== null"
                        @click="toggleExtension(extension)"
                        :class="extension.enabled ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'"
                        class="relative mt-0.5 inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-900 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <span
                            aria-hidden="true"
                            :class="extension.enabled ? 'translate-x-5' : 'translate-x-0'"
                            class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition"
                        ></span>
                    </button>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <label
                                :for="'php-ext-' + extension.key"
                                class="font-medium text-slate-900 dark:text-white"
                                x-text="extension.label"
                            ></label>
                            <code class="text-xs font-mono text-slate-500 dark:text-slate-400" x-text="extension.key"></code>
                            <span
                                x-show="busyKey === extension.key && extension.enabled"
                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300"
                            >
                                <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                Installing
                            </span>
                            <span
                                x-show="busyKey !== extension.key && extension.installed"
                                class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300"
                            >
                                Loaded
                            </span>
                            <span
                                x-show="busyKey !== extension.key && extension.enabled && !extension.installed"
                                class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300"
                            >
                                Pending install
                            </span>
                        </div>
                        <p
                            x-show="extension.description"
                            class="text-sm text-slate-600 dark:text-slate-400 mt-1"
                            x-text="extension.description"
                        ></p>
                        <p
                            x-show="busyKey === extension.key && extension.enabled"
                            class="text-xs text-blue-700 dark:text-blue-300 mt-2"
                        >
                            Installing now — compiling an extension can take a few minutes.
                        </p>
                    </div>
                </div>
            </template>
            @if ($phpExtensionsPanel['optional'] === [])
                <div class="p-5 text-sm text-slate-600 dark:text-slate-400">No optional extensions are configured.</div>
            @endif
        </div>
    </div>

    <p class="text-xs text-slate-500 dark:text-slate-400">
        Turning an extension off updates your saved preferences but does not remove it from the base runtime image.
    </p>
</div>

@push('scripts')
<script>
function phpExtensionsPanel() {
    return {
        containerRunning: {{ $phpExtensionsPanel['container_running'] ? 'true' : 'false' }},
        updateUrl: @js(route('customer.services.container.php-extensions.update', $service)),
        extensions: @js(array_values($phpExtensionsPanel['optional'])),
        busyKey: null,
        successMessage: '',
        errorMessage: '',

        async toggleExtension(extension) {
            if (!this.containerRunning || this.busyKey) {
                return;
            }

            const previous = {
                enabled: extension.enabled,
                installed: extension.installed,
            };
            const nextEnabled = !extension.enabled;

            extension.enabled = nextEnabled;
            this.busyKey = extension.key;
            this.successMessage = '';
            this.errorMessage = '';

            try {
                const response = await fetch(this.updateUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        extension: extension.key,
                        enabled: nextEnabled,
                    }),
                });

                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    this.errorMessage = data.error || 'Failed to update PHP extension.';
                    if (data.extension) {
                        extension.enabled = !!data.extension.enabled;
                        if (typeof data.extension.installed === 'boolean') {
                            extension.installed = data.extension.installed;
                        } else {
                            extension.installed = previous.installed;
                        }
                    } else {
                        extension.enabled = previous.enabled;
                        extension.installed = previous.installed;
                    }
                    return;
                }

                extension.enabled = !!data.extension?.enabled;
                if (typeof data.extension?.installed === 'boolean') {
                    extension.installed = data.extension.installed;
                }
                this.successMessage = data.message || 'PHP extension updated.';
            } catch (error) {
                extension.enabled = previous.enabled;
                extension.installed = previous.installed;
                this.errorMessage = 'Network error while updating PHP extensions.';
            } finally {
                this.busyKey = null;
            }
        },
    };
}
</script>
@endpush
@endif
