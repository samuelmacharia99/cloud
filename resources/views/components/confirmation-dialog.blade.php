@props(['title' => 'Confirm Action', 'message' => 'Are you sure?', 'confirmText' => 'Confirm', 'cancelText' => 'Cancel', 'danger' => false, 'action' => null])

<div
    x-data="{ open: false }"
    class="inline-block"
>
    <!-- Trigger Button -->
    <button
        @click="open = true"
        type="button"
        {{ $attributes->merge([
            'class' => 'inline-flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium transition-colors ' .
            ($danger
                ? 'bg-red-600 hover:bg-red-700 text-white dark:bg-red-700 dark:hover:bg-red-800'
                : 'bg-blue-600 hover:bg-blue-700 text-white dark:bg-blue-700 dark:hover:bg-blue-800'
            )
        ]) }}
    >
        {{ $slot }}
    </button>

    <!-- Modal Backdrop -->
    <div
        x-show="open"
        @click="open = false"
        class="fixed inset-0 z-50 bg-slate-900/50 dark:bg-slate-950/75 transition-opacity"
        style="display: none;"
        x-transition
    ></div>

    <!-- Modal Dialog -->
    <div
        x-show="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        style="display: none;"
        x-transition
    >
        <div
            @click.stop
            class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 shadow-xl border border-slate-200 dark:border-slate-800"
        >
            <!-- Header -->
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>
            </div>

            <!-- Body -->
            <div class="px-6 py-4">
                <p class="text-slate-600 dark:text-slate-400">{{ $message }}</p>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 rounded-b-xl">
                <button
                    type="button"
                    @click="open = false"
                    class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors font-medium"
                >
                    {{ $cancelText }}
                </button>

                @if ($action)
                    <form method="POST" action="{{ $action }}" style="display: inline;">
                        @csrf
                        <button
                            type="submit"
                            class="{{ $danger ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700' }} px-4 py-2 rounded-lg text-white transition-colors font-medium"
                        >
                            {{ $confirmText }}
                        </button>
                    </form>
                @else
                    <button
                        type="button"
                        @click="open = false"
                        class="{{ $danger ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700' }} px-4 py-2 rounded-lg text-white transition-colors font-medium"
                    >
                        {{ $confirmText }}
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
