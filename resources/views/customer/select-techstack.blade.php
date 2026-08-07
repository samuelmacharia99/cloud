@extends('layouts.customer')

@section('title', 'Select Your Techstack')

@section('content')
<div class="space-y-8" x-data="techstackSelector()" @keydown.escape="showStackModal = false">
    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Deploy Your Application</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1 max-w-2xl">Pick a stack to continue. You’ll configure roles and database next — everything runs on <strong>application hosting</strong>.</p>
        </div>
        <a href="{{ route('customer.cart.index') }}" class="relative shrink-0">
            <svg class="w-6 h-6 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            @if($cartCount > 0)
                <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">{{ $cartCount }}</span>
            @endif
        </a>
    </div>

    @if(!empty($attachDomain))
        <div class="bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 rounded-xl p-4 text-sm text-blue-900 dark:text-blue-100">
            <p class="font-semibold">Hosting for {{ $attachDomain['fqdn'] }}</p>
            <p class="mt-1 text-blue-800 dark:text-blue-200">Select a stack and plan below. Your domain stays in the cart — checkout once for domain + hosting.</p>
        </div>
    @endif

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
    @endphp

    <!-- Soft canvas + language grid -->
    <div class="techstack-soft-canvas rounded-[2rem] p-4 sm:p-8">
        <div class="techstack-soft-grid grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-7">
            @foreach($languages as $language)
                @php
                    $glow = $stackGlow[strtolower((string) $language->slug)] ?? '148, 163, 184';
                @endphp
                <button
                    type="button"
                    @click="selectLanguageAndShowModal({{ $language->id }})"
                    class="techstack-soft-card group relative aspect-square flex flex-col items-center justify-center gap-5 sm:gap-6 p-5 sm:p-8 rounded-[1.85rem] text-center transition-all duration-300 ease-out focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-400 dark:focus-visible:ring-slate-500"
                    style="--stack-glow: {{ $glow }};"
                    :class="selectedLanguage.id === {{ $language->id }} ? 'is-selected' : ''"
                    :aria-pressed="selectedLanguage.id === {{ $language->id }} ? 'true' : 'false'"
                >
                    <span class="techstack-soft-logo relative flex items-center justify-center transition-transform duration-300 group-hover:scale-110 group-hover:-translate-y-0.5">
                        <x-tech-stack-icon :slug="$language->slug" class="w-[4.5rem] h-[4.5rem] sm:w-24 sm:h-24" />
                    </span>
                    <span class="text-base sm:text-lg font-semibold tracking-tight text-slate-800 dark:text-slate-100">
                        {{ $language->name }}
                    </span>
                    <span
                        x-show="selectedLanguage.id === {{ $language->id }}"
                        x-cloak
                        class="absolute top-3.5 right-3.5 flex h-6 w-6 items-center justify-center rounded-full bg-slate-900/90 text-white shadow-md dark:bg-white dark:text-slate-900"
                    >
                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                    </span>
                </button>
            @endforeach
        </div>
    </div>

    <style>
        .techstack-soft-canvas {
            background:
                radial-gradient(ellipse 80% 60% at 50% 0%, rgba(255, 255, 255, 0.9), transparent 70%),
                linear-gradient(180deg, #f3f4f6 0%, #eceef2 100%);
        }

        .dark .techstack-soft-canvas {
            background:
                radial-gradient(ellipse 80% 60% at 50% 0%, rgba(51, 65, 85, 0.45), transparent 70%),
                linear-gradient(180deg, #0f172a 0%, #020617 100%);
        }

        .techstack-soft-logo {
            filter: drop-shadow(0 8px 14px rgba(15, 23, 42, 0.1));
        }

        .dark .techstack-soft-logo {
            filter: drop-shadow(0 8px 14px rgba(0, 0, 0, 0.35));
        }

        .techstack-soft-card {
            isolation: isolate;
            overflow: visible;
            background: linear-gradient(160deg, #ffffff 0%, #f6f7f9 55%, #eef0f3 100%);
            border: 1px solid rgba(255, 255, 255, 0.9);
            box-shadow:
                12px 16px 32px rgba(15, 23, 42, 0.09),
                -10px -10px 24px rgba(255, 255, 255, 0.95),
                inset 0 1px 0 rgba(255, 255, 255, 1),
                0 22px 40px -14px rgba(var(--stack-glow), 0.42);
        }

        .techstack-soft-card::after {
            content: '';
            position: absolute;
            inset: auto 12% -6% 12%;
            height: 28%;
            border-radius: 999px;
            background: radial-gradient(ellipse at center, rgba(var(--stack-glow), 0.45), transparent 70%);
            filter: blur(18px);
            pointer-events: none;
            z-index: -1;
            opacity: 0.85;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        .techstack-soft-card:hover {
            transform: translateY(-6px);
            box-shadow:
                14px 22px 40px rgba(15, 23, 42, 0.11),
                -10px -10px 26px rgba(255, 255, 255, 1),
                inset 0 1px 0 rgba(255, 255, 255, 1),
                0 28px 48px -12px rgba(var(--stack-glow), 0.55);
        }

        .techstack-soft-card:hover::after {
            opacity: 1;
            transform: scale(1.05);
        }

        .techstack-soft-card.is-selected {
            transform: translateY(-3px);
            box-shadow:
                12px 18px 36px rgba(15, 23, 42, 0.1),
                -8px -8px 20px rgba(255, 255, 255, 0.98),
                inset 0 0 0 1.5px rgba(var(--stack-glow), 0.5),
                0 28px 50px -12px rgba(var(--stack-glow), 0.6);
        }

        .techstack-soft-card:active {
            transform: translateY(0) scale(0.985);
        }

        .dark .techstack-soft-card {
            background: linear-gradient(160deg, #1e293b 0%, #0f172a 100%);
            border-color: rgba(148, 163, 184, 0.12);
            box-shadow:
                12px 16px 32px rgba(0, 0, 0, 0.45),
                -4px -4px 16px rgba(148, 163, 184, 0.05),
                inset 0 1px 0 rgba(255, 255, 255, 0.04),
                0 22px 40px -14px rgba(var(--stack-glow), 0.3);
        }

        .dark .techstack-soft-card:hover,
        .dark .techstack-soft-card.is-selected {
            box-shadow:
                14px 22px 40px rgba(0, 0, 0, 0.55),
                -4px -4px 16px rgba(148, 163, 184, 0.08),
                inset 0 0 0 1.5px rgba(var(--stack-glow), 0.45),
                0 28px 50px -12px rgba(var(--stack-glow), 0.45);
        }

        /* Modal soft option rows */
        .techstack-soft-option {
            background: linear-gradient(145deg, #ffffff 0%, #f5f6f8 100%);
            border: 1px solid rgba(226, 232, 240, 0.9);
            box-shadow:
                6px 8px 16px rgba(15, 23, 42, 0.05),
                -4px -4px 12px rgba(255, 255, 255, 0.9);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .techstack-soft-option:hover {
            transform: translateY(-1px);
            border-color: rgba(148, 163, 184, 0.55);
        }

        .techstack-soft-option.is-selected {
            border-color: rgba(37, 99, 235, 0.45);
            box-shadow:
                6px 10px 20px rgba(37, 99, 235, 0.1),
                -4px -4px 12px rgba(255, 255, 255, 0.95),
                inset 0 0 0 1px rgba(37, 99, 235, 0.2);
            background: linear-gradient(145deg, #eff6ff 0%, #f8fafc 100%);
        }

        .dark .techstack-soft-option {
            background: linear-gradient(145deg, #1e293b 0%, #0f172a 100%);
            border-color: rgba(51, 65, 85, 0.9);
            box-shadow: 6px 8px 16px rgba(0, 0, 0, 0.25);
        }

        .dark .techstack-soft-option.is-selected {
            border-color: rgba(96, 165, 250, 0.45);
            background: linear-gradient(145deg, #1e3a5f 0%, #0f172a 100%);
            box-shadow:
                6px 10px 20px rgba(0, 0, 0, 0.35),
                inset 0 0 0 1px rgba(96, 165, 250, 0.25);
        }

        .techstack-soft-modal {
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            box-shadow:
                0 25px 50px -12px rgba(15, 23, 42, 0.25),
                0 0 0 1px rgba(226, 232, 240, 0.8);
        }

        .dark .techstack-soft-modal {
            background: linear-gradient(180deg, #0f172a 0%, #020617 100%);
            box-shadow:
                0 25px 50px -12px rgba(0, 0, 0, 0.55),
                0 0 0 1px rgba(51, 65, 85, 0.8);
        }
    </style>

    <!-- Hidden form for static-site skip -->
    <form id="skip-db-form" method="POST" action="{{ route('customer.confirm-techstack.store') }}" class="hidden">
        @csrf
        <input type="hidden" id="skip-db-form-language" name="language_id" value="">
        <input type="hidden" name="database_id" value="">
        <input type="hidden" name="frontend" value="static">
        <input type="hidden" name="deployment_platform" value="container">
    </form>

    <!-- Stack Builder Modal -->
    <div
        x-show="showStackModal"
        x-cloak
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        class="fixed inset-0 bg-black/50 dark:bg-black/70 flex items-center justify-center p-4 z-50"
        @click.self="showStackModal = false"
    >
        <div
            @click.stop
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            class="techstack-soft-modal rounded-[1.5rem] max-w-2xl w-full max-h-[90vh] overflow-y-auto"
        >
            <div class="sticky top-0 flex items-center justify-between p-6 border-b border-slate-200/80 dark:border-slate-800/80 bg-white/90 dark:bg-slate-950/90 backdrop-blur z-10 rounded-t-[1.5rem]">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Configure your stack</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                        Compatible options for <span class="font-semibold" x-text="selectedLanguage.name"></span>
                    </p>
                </div>
                <button
                    @click="showStackModal = false"
                    type="button"
                    class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 rounded-full p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="p-6 space-y-6">
                <template x-if="loading">
                    <div class="text-center py-8">
                        <div class="inline-block animate-spin">
                            <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 mt-2">Loading compatible options...</p>
                    </div>
                </template>

                <template x-if="!loading && stackOptions">
                    <div class="space-y-6">
                        <div class="techstack-soft-option p-4 rounded-2xl">
                            <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide">Backend</p>
                            <p class="font-semibold text-slate-900 dark:text-white mt-0.5" x-text="selectedLanguage.name"></p>
                        </div>

                        <template x-if="stackOptions.framework.show">
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white mb-3">
                                    Framework
                                    <span class="text-red-500" x-show="stackOptions.framework.required">*</span>
                                </p>
                                <div class="space-y-2.5">
                                    <template x-for="option in stackOptions.framework.options" :key="option.value">
                                        <label class="techstack-soft-option block p-4 rounded-2xl cursor-pointer"
                                            :class="selectedFramework === option.value ? 'is-selected' : ''"
                                        >
                                            <div class="flex items-center gap-3">
                                                <input
                                                    type="radio"
                                                    name="framework"
                                                    :value="option.value"
                                                    x-model="selectedFramework"
                                                    @change="onFrameworkChange()"
                                                    class="mt-0.5"
                                                >
                                                <span class="font-medium text-slate-900 dark:text-white" x-text="option.label"></span>
                                            </div>
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="stackOptions.frontend.show">
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white mb-3">
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
                                                <span class="font-medium text-slate-900 dark:text-white" x-text="option.label"></span>
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
                                <p class="text-sm font-semibold text-slate-900 dark:text-white mb-3">
                                    Database
                                    <span class="text-red-500" x-show="stackOptions.database.required">*</span>
                                </p>
                                <div class="space-y-2.5">
                                    <template x-if="stackOptions.database.allow_none">
                                        <label class="techstack-soft-option block p-4 rounded-2xl cursor-pointer"
                                            :class="selectedDatabaseId === '' ? 'is-selected' : ''"
                                        >
                                            <div class="flex items-center gap-3">
                                                <input
                                                    type="radio"
                                                    name="database_id"
                                                    value=""
                                                    x-model="selectedDatabaseId"
                                                    class="mt-0.5"
                                                >
                                                <span class="font-medium text-slate-900 dark:text-white">None</span>
                                            </div>
                                        </label>
                                    </template>
                                    <template x-for="db in stackOptions.database.options" :key="db.id">
                                        <label class="techstack-soft-option block p-4 rounded-2xl cursor-pointer"
                                            :class="String(selectedDatabaseId) === String(db.id) ? 'is-selected' : ''"
                                        >
                                            <div class="flex items-start gap-3">
                                                <input
                                                    type="radio"
                                                    name="database_id"
                                                    :value="db.id"
                                                    x-model="selectedDatabaseId"
                                                    class="mt-1"
                                                >
                                                <div class="flex-1">
                                                    <span class="font-semibold text-slate-900 dark:text-white" x-text="db.name"></span>
                                                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1" x-text="'Type: ' + db.type"></p>
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

            <div class="border-t border-slate-200/80 dark:border-slate-800/80 p-6 space-y-4" x-show="!loading && stackOptions">
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80">
                    <p class="font-semibold text-slate-900 dark:text-slate-100">Application hosting</p>
                    <p class="text-sm mt-1 text-slate-600 dark:text-slate-400"
                        x-text="(selectedLanguage.name || 'Your application') + ' will run on isolated application hosting with Git deploy and a modern console.'"></p>
                </div>

                <div class="flex gap-3 pt-2">
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
                            class="w-full px-6 py-3.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-2xl font-semibold shadow-lg shadow-blue-600/20 transition"
                        >
                            Continue to Packages
                        </button>
                    </form>
                    <button
                        @click="closeModal()"
                        type="button"
                        class="px-6 py-3.5 border border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-2xl font-semibold hover:bg-slate-100 dark:hover:bg-slate-800 transition"
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
