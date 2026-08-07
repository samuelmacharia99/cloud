@extends('layouts.customer')

@section('title', 'Select Your Techstack')

@section('content')
@php
    $stackGlow = [
        'nodejs' => '51, 153, 51',
        'python' => '55, 118, 171',
        'laravel' => '255, 45, 32',
        'php' => '119, 123, 180',
        'wordpress' => '33, 117, 155',
        'static-site' => '227, 79, 38',
        'ruby' => '204, 52, 45',
        'ghost' => '21, 23, 26',
        'strapi' => '73, 69, 255',
        'go' => '0, 173, 216',
        'golang' => '0, 173, 216',
    ];
    $stackHint = [
        'nodejs' => 'JS runtime',
        'python' => 'Python apps',
        'laravel' => 'PHP framework',
        'php' => 'Classic PHP',
        'wordpress' => 'CMS',
        'static-site' => 'HTML / JAMstack',
        'ruby' => 'Ruby apps',
        'ghost' => 'Publishing',
        'strapi' => 'Headless CMS',
        'go' => 'Go services',
        'golang' => 'Go services',
    ];
@endphp

<div class="space-y-8" x-data="techstackSelector()" @keydown.escape="showStackModal = false">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-ink-500 dark:text-ink-400 mb-2">
                Deploy · Runtime
            </p>
            <h1 class="font-display text-3xl sm:text-4xl text-ink-950 dark:text-white leading-[1.05]">
                What are you shipping?
            </h1>
            <p class="text-ink-600 dark:text-ink-400 mt-2 max-w-xl text-[15px] leading-relaxed">
                Pick a runtime. Configure framework, frontend, and database next — then push with Git on isolated application hosting.
            </p>
        </div>
        <a
            href="{{ route('customer.cart.index') }}"
            class="shrink-0 inline-flex items-center gap-2 rounded-full border border-ink-200/80 dark:border-ink-700/80 bg-white/70 dark:bg-ink-900/60 backdrop-blur px-3.5 py-2 text-sm font-medium text-ink-700 dark:text-ink-200 hover:border-ink-300 dark:hover:border-ink-600 transition shadow-sm"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            Cart
            @if($cartCount > 0)
                <span class="min-w-[1.25rem] h-5 px-1.5 rounded-full bg-ink-950 dark:bg-brand-400 text-white dark:text-ink-950 text-[11px] font-bold flex items-center justify-center">{{ $cartCount }}</span>
            @endif
        </a>
    </div>

    @if(!empty($attachDomain))
        <div class="rounded-2xl border border-sky-200/80 dark:border-sky-800/60 bg-sky-50/80 dark:bg-sky-950/30 px-4 py-3.5 text-sm text-sky-950 dark:text-sky-100">
            <p class="font-semibold tracking-tight">Attaching {{ $attachDomain['fqdn'] }}</p>
            <p class="mt-0.5 text-sky-800/90 dark:text-sky-200/90">Domain stays in your cart — one checkout for domain + hosting.</p>
        </div>
    @endif

    <div class="techstack-soft-canvas relative overflow-hidden rounded-3xl p-4 sm:p-6 max-w-4xl">
        <div class="relative mb-4 flex items-center justify-between gap-3">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-400">
                Choose a stack
            </p>
            <p class="text-xs text-ink-400 dark:text-ink-500 hidden sm:block">
                {{ $languages->count() }} runtimes
            </p>
        </div>

        <div class="techstack-soft-grid grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-3.5">
            @foreach($languages as $index => $language)
                @php
                    $slug = strtolower((string) $language->slug);
                    $glow = $stackGlow[$slug] ?? '148, 163, 184';
                    $hint = $stackHint[$slug] ?? 'Application hosting';
                @endphp
                <button
                    type="button"
                    @click="selectLanguageAndShowModal({{ $language->id }})"
                    class="techstack-soft-card group relative aspect-[5/6] sm:aspect-square flex flex-col items-center justify-center gap-2.5 p-3.5 sm:p-4 rounded-2xl text-center focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-transparent"
                    style="--stack-glow: {{ $glow }}; --enter-delay: {{ min($index * 40, 280) }}ms;"
                    :class="selectedLanguage.id === {{ $language->id }} ? 'is-selected' : ''"
                    :aria-pressed="selectedLanguage.id === {{ $language->id }} ? 'true' : 'false'"
                >
                    <span class="techstack-soft-logo relative z-[1] flex items-center justify-center">
                        <x-tech-stack-icon :slug="$language->slug" class="w-11 h-11 sm:w-14 sm:h-14" />
                    </span>
                    <span class="relative z-[1] space-y-0.5 px-1">
                        <span class="block text-[13px] sm:text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                            {{ $language->name }}
                        </span>
                        <span class="block text-[10px] sm:text-[11px] font-medium tracking-wide text-ink-500/90 dark:text-ink-400 uppercase">
                            {{ $hint }}
                        </span>
                    </span>
                    <span
                        x-show="selectedLanguage.id === {{ $language->id }}"
                        x-cloak
                        class="absolute top-2.5 right-2.5 z-[1] flex h-5 w-5 items-center justify-center rounded-full bg-ink-950 text-white dark:bg-brand-400 dark:text-ink-950 shadow-sm"
                    >
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                    </span>
                </button>
            @endforeach
        </div>
    </div>

    <style>
        @keyframes techstack-card-in {
            from { opacity: 0; transform: translateY(10px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .techstack-soft-canvas {
            background:
                radial-gradient(ellipse 90% 70% at 10% -20%, rgba(245, 158, 11, 0.08), transparent 55%),
                radial-gradient(ellipse 60% 50% at 100% 100%, rgba(255, 255, 255, 0.7), transparent 50%),
                linear-gradient(165deg, #f4f4f5 0%, #e8e8ec 100%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
        }

        .dark .techstack-soft-canvas {
            background:
                radial-gradient(ellipse 90% 70% at 10% -20%, rgba(245, 158, 11, 0.1), transparent 55%),
                radial-gradient(ellipse 60% 50% at 100% 110%, rgba(30, 41, 59, 0.5), transparent 50%),
                linear-gradient(165deg, #111827 0%, #020617 100%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }

        .techstack-soft-logo {
            transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
            filter: drop-shadow(0 6px 12px rgba(15, 23, 42, 0.12));
        }

        .dark .techstack-soft-logo {
            filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.4));
        }

        .techstack-soft-card:hover .techstack-soft-logo {
            transform: translateY(-3px) scale(1.06);
        }

        .techstack-soft-card {
            isolation: isolate;
            overflow: visible;
            animation: techstack-card-in 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            animation-delay: var(--enter-delay, 0ms);
            background: linear-gradient(165deg, rgba(255, 255, 255, 0.95) 0%, rgba(248, 250, 252, 0.92) 100%);
            border: 1px solid rgba(255, 255, 255, 0.85);
            box-shadow:
                0 1px 0 rgba(255, 255, 255, 0.9) inset,
                0 10px 24px -8px rgba(15, 23, 42, 0.12),
                0 18px 36px -16px rgba(var(--stack-glow), 0.28);
            transition:
                transform 0.35s cubic-bezier(0.22, 1, 0.36, 1),
                box-shadow 0.35s cubic-bezier(0.22, 1, 0.36, 1),
                border-color 0.25s ease;
        }

        .techstack-soft-card::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: radial-gradient(circle at 50% 18%, rgba(var(--stack-glow), 0.14), transparent 55%);
            opacity: 0;
            transition: opacity 0.35s ease;
            pointer-events: none;
        }

        .techstack-soft-card::after {
            content: '';
            position: absolute;
            inset: auto 18% -10% 18%;
            height: 36%;
            border-radius: 999px;
            background: radial-gradient(ellipse at center, rgba(var(--stack-glow), 0.5), transparent 72%);
            filter: blur(16px);
            pointer-events: none;
            z-index: -1;
            opacity: 0.55;
            transition: opacity 0.35s ease, transform 0.35s ease;
        }

        .techstack-soft-card:hover {
            transform: translateY(-5px);
            border-color: rgba(var(--stack-glow), 0.28);
            box-shadow:
                0 1px 0 rgba(255, 255, 255, 0.95) inset,
                0 16px 32px -10px rgba(15, 23, 42, 0.16),
                0 24px 44px -14px rgba(var(--stack-glow), 0.42);
        }

        .techstack-soft-card:hover::before,
        .techstack-soft-card.is-selected::before { opacity: 1; }

        .techstack-soft-card:hover::after,
        .techstack-soft-card.is-selected::after {
            opacity: 0.95;
            transform: scale(1.08);
        }

        .techstack-soft-card.is-selected {
            transform: translateY(-3px);
            border-color: rgba(var(--stack-glow), 0.45);
            box-shadow:
                0 0 0 1.5px rgba(var(--stack-glow), 0.35),
                0 16px 32px -10px rgba(15, 23, 42, 0.14),
                0 24px 44px -12px rgba(var(--stack-glow), 0.48);
        }

        .techstack-soft-card:active {
            transform: translateY(-1px) scale(0.985);
        }

        .dark .techstack-soft-card {
            background: linear-gradient(165deg, rgba(30, 41, 59, 0.9) 0%, rgba(15, 23, 42, 0.95) 100%);
            border-color: rgba(148, 163, 184, 0.12);
            box-shadow:
                0 1px 0 rgba(255, 255, 255, 0.04) inset,
                0 12px 28px -10px rgba(0, 0, 0, 0.55),
                0 18px 36px -16px rgba(var(--stack-glow), 0.22);
        }

        .dark .techstack-soft-card:hover,
        .dark .techstack-soft-card.is-selected {
            border-color: rgba(var(--stack-glow), 0.4);
            box-shadow:
                0 0 0 1.5px rgba(var(--stack-glow), 0.3),
                0 16px 34px -10px rgba(0, 0, 0, 0.6),
                0 24px 44px -12px rgba(var(--stack-glow), 0.35);
        }

        .techstack-soft-option {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(226, 232, 240, 0.95);
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }

        .techstack-soft-option:hover {
            transform: translateY(-1px);
            border-color: rgba(148, 163, 184, 0.7);
        }

        .techstack-soft-option.is-selected {
            border-color: rgba(245, 158, 11, 0.55);
            background: linear-gradient(145deg, #fffbeb 0%, #ffffff 100%);
            box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.2), 0 8px 18px -10px rgba(245, 158, 11, 0.35);
        }

        .dark .techstack-soft-option {
            background: rgba(15, 23, 42, 0.7);
            border-color: rgba(51, 65, 85, 0.9);
            box-shadow: none;
        }

        .dark .techstack-soft-option.is-selected {
            border-color: rgba(251, 191, 36, 0.45);
            background: linear-gradient(145deg, rgba(69, 26, 3, 0.45) 0%, rgba(15, 23, 42, 0.9) 100%);
            box-shadow: 0 0 0 1px rgba(251, 191, 36, 0.2);
        }

        .techstack-soft-modal {
            background: linear-gradient(180deg, #ffffff 0%, #fafafa 100%);
            box-shadow:
                0 25px 50px -12px rgba(15, 23, 42, 0.28),
                0 0 0 1px rgba(226, 232, 240, 0.85);
        }

        .dark .techstack-soft-modal {
            background: linear-gradient(180deg, #0f172a 0%, #020617 100%);
            box-shadow:
                0 25px 50px -12px rgba(0, 0, 0, 0.6),
                0 0 0 1px rgba(51, 65, 85, 0.85);
        }

        @media (prefers-reduced-motion: reduce) {
            .techstack-soft-card { animation: none; }
            .techstack-soft-card,
            .techstack-soft-card:hover,
            .techstack-soft-card.is-selected,
            .techstack-soft-logo { transition: none; }
        }
    </style>

    <form id="skip-db-form" method="POST" action="{{ route('customer.confirm-techstack.store') }}" class="hidden">
        @csrf
        <input type="hidden" id="skip-db-form-language" name="language_id" value="">
        <input type="hidden" name="database_id" value="">
        <input type="hidden" name="frontend" value="static">
        <input type="hidden" name="deployment_platform" value="container">
    </form>

    <div
        x-show="showStackModal"
        x-cloak
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        class="fixed inset-0 bg-ink-950/55 dark:bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 z-50"
        @click.self="showStackModal = false"
    >
        <div
            @click.stop
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            class="techstack-soft-modal rounded-[1.5rem] max-w-2xl w-full max-h-[90vh] overflow-y-auto"
        >
            <div class="sticky top-0 flex items-center justify-between p-6 border-b border-ink-200/80 dark:border-ink-800/80 bg-white/90 dark:bg-ink-950/90 backdrop-blur z-10 rounded-t-[1.5rem]">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-400 mb-1">Stack builder</p>
                    <h2 class="text-xl sm:text-2xl font-display text-ink-950 dark:text-white">Configure <span x-text="selectedLanguage.name"></span></h2>
                </div>
                <button
                    @click="showStackModal = false"
                    type="button"
                    class="text-ink-400 hover:text-ink-700 dark:hover:text-ink-200 rounded-full p-1.5 hover:bg-ink-100 dark:hover:bg-ink-800 transition"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="p-6 space-y-6">
                <template x-if="loading">
                    <div class="text-center py-10">
                        <div class="inline-flex h-10 w-10 items-center justify-center rounded-full border-2 border-brand-500/30 border-t-brand-500 animate-spin"></div>
                        <p class="text-ink-600 dark:text-ink-400 mt-3 text-sm">Loading compatible options…</p>
                    </div>
                </template>

                <template x-if="!loading && stackOptions">
                    <div class="space-y-6">
                        <div class="techstack-soft-option p-4 rounded-2xl">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-500 dark:text-ink-400">Backend</p>
                            <p class="font-semibold text-ink-950 dark:text-white mt-0.5" x-text="selectedLanguage.name"></p>
                        </div>

                        <template x-if="stackOptions.framework.show">
                            <div>
                                <p class="text-sm font-semibold text-ink-950 dark:text-white mb-3">
                                    Framework
                                    <span class="text-red-500" x-show="stackOptions.framework.required">*</span>
                                </p>
                                <div class="space-y-2.5">
                                    <template x-for="option in stackOptions.framework.options" :key="option.value">
                                        <label class="techstack-soft-option block p-4 rounded-2xl cursor-pointer"
                                            :class="selectedFramework === option.value ? 'is-selected' : ''"
                                        >
                                            <div class="flex items-center gap-3">
                                                <input type="radio" name="framework" :value="option.value" x-model="selectedFramework" @change="onFrameworkChange()" class="mt-0.5">
                                                <span class="font-medium text-ink-950 dark:text-white" x-text="option.label"></span>
                                            </div>
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="stackOptions.frontend.show">
                            <div>
                                <p class="text-sm font-semibold text-ink-950 dark:text-white mb-3">
                                    Frontend
                                    <span class="text-red-500" x-show="stackOptions.frontend.required">*</span>
                                </p>
                                <div class="space-y-2.5">
                                    <template x-for="option in stackOptions.frontend.options" :key="option.value">
                                        <label class="techstack-soft-option block p-4 rounded-2xl cursor-pointer"
                                            :class="selectedFrontend === option.value ? 'is-selected' : ''"
                                        >
                                            <div class="flex items-center gap-3">
                                                <input
                                                    type="radio"
                                                    name="frontend"
                                                    :value="option.value"
                                                    x-model="selectedFrontend"
                                                    :disabled="option.locked && stackOptions.frontend.options.length === 1"
                                                    class="mt-0.5"
                                                >
                                                <span class="font-medium text-ink-950 dark:text-white" x-text="option.label"></span>
                                            </div>
                                        </label>
                                    </template>
                                </div>
                                <p
                                    class="text-xs text-amber-700 dark:text-amber-300 mt-2"
                                    x-show="selectedFrontend && selectedFrontend !== 'none'"
                                    x-text="stackOptions.frontend.deferred_note"
                                ></p>
                            </div>
                        </template>

                        <template x-if="stackOptions.database.show">
                            <div>
                                <p class="text-sm font-semibold text-ink-950 dark:text-white mb-3">
                                    Database
                                    <span class="text-red-500" x-show="stackOptions.database.required">*</span>
                                </p>
                                <div class="space-y-2.5">
                                    <template x-if="stackOptions.database.allow_none">
                                        <label class="techstack-soft-option block p-4 rounded-2xl cursor-pointer"
                                            :class="selectedDatabaseId === '' ? 'is-selected' : ''"
                                        >
                                            <div class="flex items-center gap-3">
                                                <input type="radio" name="database_id" value="" x-model="selectedDatabaseId" class="mt-0.5">
                                                <span class="font-medium text-ink-950 dark:text-white">None</span>
                                            </div>
                                        </label>
                                    </template>
                                    <template x-for="db in stackOptions.database.options" :key="db.id">
                                        <label class="techstack-soft-option block p-4 rounded-2xl cursor-pointer"
                                            :class="String(selectedDatabaseId) === String(db.id) ? 'is-selected' : ''"
                                        >
                                            <div class="flex items-start gap-3">
                                                <input type="radio" name="database_id" :value="db.id" x-model="selectedDatabaseId" class="mt-1">
                                                <div class="flex-1">
                                                    <span class="font-semibold text-ink-950 dark:text-white" x-text="db.name"></span>
                                                    <p class="text-sm text-ink-600 dark:text-ink-400 mt-1" x-text="'Type: ' + db.type"></p>
                                                </div>
                                            </div>
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <div class="border-t border-ink-200/80 dark:border-ink-800/80 p-6 space-y-4" x-show="!loading && stackOptions">
                <div class="p-4 rounded-2xl bg-ink-50 dark:bg-ink-900/50 border border-ink-200/80 dark:border-ink-700/80">
                    <p class="font-semibold text-ink-950 dark:text-ink-100">Application hosting</p>
                    <p class="text-sm mt-1 text-ink-600 dark:text-ink-400"
                        x-text="(selectedLanguage.name || 'Your application') + ' runs on isolated hosting with Git deploy and a modern console.'"></p>
                </div>

                <div class="flex gap-3 pt-1">
                    <form :action="confirmTechstackUrl" method="POST" class="flex-1">
                        @csrf
                        <input type="hidden" name="language_id" :value="selectedLanguage.id">
                        <input type="hidden" name="database_id" :value="selectedDatabaseId">
                        <input type="hidden" name="framework" :value="selectedFramework || ''">
                        <input type="hidden" name="frontend" :value="selectedFrontend || ''">
                        <input type="hidden" name="deployment_platform" value="container">
                        <button
                            type="submit"
                            :disabled="!canContinue"
                            class="w-full px-6 py-3.5 bg-ink-950 hover:bg-ink-800 dark:bg-brand-400 dark:hover:bg-brand-300 dark:text-ink-950 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-2xl font-semibold shadow-lg shadow-ink-950/15 dark:shadow-brand-400/20 transition"
                        >
                            Continue to packages
                        </button>
                    </form>
                    <button
                        @click="closeModal()"
                        type="button"
                        class="px-6 py-3.5 border border-ink-200 dark:border-ink-600 text-ink-700 dark:text-ink-300 rounded-2xl font-semibold hover:bg-ink-100 dark:hover:bg-ink-800 transition"
                    >
                        Back
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function techstackSelector() {
    return {
        selectedLanguage: {},
        stackOptions: null,
        selectedFramework: '',
        selectedFrontend: '',
        selectedDatabaseId: '',
        showStackModal: false,
        loading: false,
        confirmTechstackUrl: @json(route('customer.confirm-techstack.store')),
        stackOptionsUrlTemplate: @json(url('/api/languages/__ID__/stack-options')),

        get canContinue() {
            if (!this.stackOptions) {
                return false;
            }

            if (this.stackOptions.framework.show && this.stackOptions.framework.required && !this.selectedFramework) {
                return false;
            }

            if (this.stackOptions.frontend.show && this.stackOptions.frontend.required && !this.selectedFrontend) {
                return false;
            }

            if (this.stackOptions.database.show && this.stackOptions.database.required && this.selectedDatabaseId === '') {
                return false;
            }

            return true;
        },

        selectLanguageAndShowModal(languageId) {
            const language = @json($languages).find(l => l.id == languageId);
            this.selectedLanguage = language;
            this.stackOptions = null;
            this.selectedFramework = '';
            this.selectedFrontend = '';
            this.selectedDatabaseId = '';

            if (language.slug === 'static-site') {
                this.$nextTick(() => {
                    document.getElementById('skip-db-form-language').value = languageId;
                    document.getElementById('skip-db-form').submit();
                });
                return;
            }

            this.showStackModal = true;
            this.loadStackOptions(languageId);
        },

        closeModal() {
            this.showStackModal = false;
        },

        async onFrameworkChange() {
            if (!this.selectedLanguage?.id) {
                return;
            }
            await this.loadStackOptions(this.selectedLanguage.id, this.selectedFramework);
        },

        async loadStackOptions(languageId, framework = null) {
            this.loading = true;
            try {
                let url = this.stackOptionsUrlTemplate.replace('__ID__', languageId);
                if (framework) {
                    url += (url.includes('?') ? '&' : '?') + 'framework=' + encodeURIComponent(framework);
                }

                const response = await fetch(url, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (!response.ok) {
                    throw new Error('Failed to load stack options');
                }

                const data = await response.json();
                this.stackOptions = data;

                if (data.framework?.value) {
                    this.selectedFramework = data.framework.value;
                } else if (data.framework?.show && data.framework.required && !this.selectedFramework) {
                    this.selectedFramework = '';
                }

                if (data.frontend?.value) {
                    this.selectedFrontend = data.frontend.value;
                } else if (data.frontend?.options?.length === 1) {
                    this.selectedFrontend = data.frontend.options[0].value;
                }

                if (data.database?.required && data.database.options?.length === 1) {
                    this.selectedDatabaseId = String(data.database.options[0].id);
                } else if (data.database?.allow_none && this.selectedDatabaseId === undefined) {
                    this.selectedDatabaseId = '';
                }
            } catch (error) {
                console.error('Error loading stack options:', error);
                this.stackOptions = null;
            } finally {
                this.loading = false;
            }
        },
    };
}
</script>
@endsection
