@extends('layouts.customer')

@section('title', 'Select Your Techstack')

@section('content')
@php
    $project = $project ?? null;
    $includedDeploy = $includedDeploy ?? false;
    $stackFormAction = $stackFormAction ?? route('customer.confirm-techstack.store');
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
        'hermes' => '201, 162, 39',
        'openclaw' => '255, 77, 26',
        'ollama' => '17, 17, 17',
        'n8n' => '234, 75, 113',
        'directus' => '102, 68, 255',
        'chatwoot' => '31, 147, 255',
        'odoo' => '113, 75, 103',
        'erpnext' => '0, 137, 255',
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
        'hermes' => 'AI agent',
        'openclaw' => 'AI gateway',
        'ollama' => 'Local LLM',
        'n8n' => 'Automation',
        'directus' => 'Headless CMS',
        'chatwoot' => 'Live chat',
        'odoo' => 'ERP',
        'erpnext' => 'ERP',
        'go' => 'Go services',
        'golang' => 'Go services',
    ];
    $skipModalSlugs = $languages
        ->filter(fn ($language) => \App\Services\TechStackRoutingService::skipsStackModal($language))
        ->pluck('slug')
        ->values();
@endphp

<div class="space-y-8" x-data="techstackSelector()" @keydown.escape="showStackModal = false">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-ink-500 dark:text-ink-400 mb-2">
                @if($project)
                    {{ $includedDeploy ? 'Project · Included deploy' : 'Project · Choose a plan' }}
                @else
                    Deploy · Runtime
                @endif
            </p>
            <h1 class="font-display text-3xl sm:text-4xl text-ink-950 dark:text-white leading-[1.05]">
                @if($project)
                    Deploy into {{ $project->name }}
                @else
                    What are you shipping?
                @endif
            </h1>
            <p class="text-ink-600 dark:text-ink-400 mt-2 max-w-xl text-[15px] leading-relaxed">
                @if($includedDeploy && $project)
                    This site uses the existing {{ $project->resolvedBillingService()?->customerPlanName() ?? 'project' }} plan. You are not billed again — usage above the plan is metered on renewal.
                @elseif($project)
                    Pick a runtime, then choose a plan. Billing starts for this project after checkout.
                @else
                    Pick a runtime. Configure framework, frontend, and database next — then push with Git on isolated application hosting.
                @endif
            </p>
        </div>
        <a
            href="{{ $project ? route('customer.projects.show', $project) : route('customer.cart.index') }}"
            class="shrink-0 inline-flex items-center gap-2 rounded-full border border-ink-200/80 dark:border-ink-700/80 bg-white/70 dark:bg-ink-900/60 backdrop-blur px-3.5 py-2 text-sm font-medium text-ink-700 dark:text-ink-200 hover:border-ink-300 dark:hover:border-ink-600 transition shadow-sm"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            {{ $project ? 'Back to project' : 'Cart' }}
            @if(!$project && $cartCount > 0)
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

    <div class="techstack-soft-canvas relative overflow-hidden rounded-3xl p-4 sm:p-6 w-full">
        <div class="relative mb-4 flex items-center justify-between gap-3">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-ink-500 dark:text-ink-400">
                Choose a stack
            </p>
            <p class="text-xs text-ink-400 dark:text-ink-500 hidden sm:block">
                {{ $languages->count() }} runtimes
            </p>
        </div>

        <div class="techstack-soft-grid grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3 sm:gap-3.5">
            @foreach($languages as $index => $language)
                @php
                    $slug = strtolower((string) $language->slug);
                    $glow = $stackGlow[$slug] ?? '148, 163, 184';
                    $hint = $stackHint[$slug] ?? 'Application hosting';
                @endphp
                <button
                    type="button"
                    @click="selectLanguageAndShowModal({{ $language->id }})"
                    class="techstack-soft-card group relative aspect-square flex flex-col items-center justify-center gap-2 sm:gap-2.5 p-3 sm:p-3.5 rounded-2xl text-center focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-transparent"
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

    <form id="skip-db-form" method="POST" action="{{ $stackFormAction }}" class="hidden" @if($includedDeploy) @submit="submitting = true" @endif>
        @csrf
        <input type="hidden" id="skip-db-form-language" name="language_id" value="">
        <input type="hidden" name="database_id" value="">
        <input type="hidden" name="frontend" value="">
        <input type="hidden" name="deployment_platform" value="container">
        @if($project && ! $includedDeploy)
            <input type="hidden" name="project_id" value="{{ $project->id }}">
        @endif
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

                        <template x-if="stackOptions.version_picker && stackOptions.version_picker.show">
                            <div>
                                <p class="text-sm font-semibold text-ink-950 dark:text-white mb-1">
                                    <span x-text="stackOptions.version_picker.label || 'Version'"></span>
                                    <span class="text-red-500" x-show="stackOptions.version_picker.required">*</span>
                                </p>
                                <p
                                    class="text-xs text-ink-600 dark:text-ink-400 mb-3"
                                    x-show="stackOptions.version_picker.help"
                                    x-text="stackOptions.version_picker.help"
                                ></p>
                                <div class="space-y-2.5">
                                    <template x-for="option in stackOptions.version_picker.options" :key="option.value">
                                        <label class="techstack-soft-option block p-4 rounded-2xl cursor-pointer"
                                            :class="selectedVersion === option.value ? 'is-selected' : ''"
                                        >
                                            <div class="flex items-start gap-3">
                                                <input type="radio" name="selected_version" :value="option.value" x-model="selectedVersion" class="mt-1">
                                                <div class="flex-1">
                                                    <span class="font-semibold text-ink-950 dark:text-white" x-text="option.label"></span>
                                                    <p
                                                        class="text-sm text-ink-600 dark:text-ink-400 mt-1"
                                                        x-show="option.description"
                                                        x-text="option.description"
                                                    ></p>
                                                </div>
                                            </div>
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </template>

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
                    <form :action="confirmTechstackUrl" method="POST" class="flex-1" @if($includedDeploy) @submit="submitting = true" @endif>
                        @csrf
                        <input type="hidden" name="language_id" :value="selectedLanguage.id">
                        <input type="hidden" name="database_id" :value="selectedDatabaseId">
                        <input type="hidden" name="framework" :value="selectedFramework || ''">
                        <input type="hidden" name="frontend" :value="selectedFrontend || ''">
                        <input type="hidden" name="selected_version" :value="selectedVersion || ''">
                        <input type="hidden" name="deployment_platform" value="container">
                        @if($project && ! $includedDeploy)
                            <input type="hidden" name="project_id" value="{{ $project->id }}">
                        @endif
                        <button
                            type="submit"
                            :disabled="!canContinue"
                            class="w-full px-6 py-3.5 bg-ink-950 hover:bg-ink-800 dark:bg-brand-400 dark:hover:bg-brand-300 dark:text-ink-950 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-2xl font-semibold shadow-lg shadow-ink-950/15 dark:shadow-brand-400/20 transition"
                        >
                            {{ $includedDeploy ? 'Deploy service' : 'Continue to packages' }}
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

    @if($includedDeploy)
        <div
            x-show="submitting"
            x-cloak
            class="fixed inset-0 z-[80] bg-ink-950/70 backdrop-blur-sm flex items-center justify-center p-6"
        >
            <div class="max-w-sm w-full rounded-3xl bg-white dark:bg-ink-900 border border-ink-200 dark:border-ink-700 p-6 text-center shadow-2xl">
                <div class="mx-auto h-12 w-12 rounded-full border-2 border-brand-500/30 border-t-brand-500 animate-spin"></div>
                <p class="mt-4 font-display text-xl text-ink-950 dark:text-white">Starting deploy</p>
                <p class="mt-2 text-sm text-ink-600 dark:text-ink-400">Opening the live console. Image pulls can take several minutes and will not time out in your browser.</p>
            </div>
        </div>
    @endif
</div>

<script>
function techstackSelector() {
    return {
        selectedLanguage: {},
        stackOptions: null,
        selectedFramework: '',
        selectedFrontend: '',
        selectedDatabaseId: '',
        selectedVersion: '',
        submitting: false,
        showStackModal: false,
        loading: false,
        confirmTechstackUrl: @json($stackFormAction),
        stackOptionsUrlTemplate: @json(url('/api/languages/__ID__/stack-options')),
        skipModalSlugs: @json($skipModalSlugs),

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

            if (this.stackOptions.version_picker?.show && this.stackOptions.version_picker.required && !this.selectedVersion) {
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
            this.selectedVersion = '';

            if (this.skipModalSlugs.includes(language.slug)) {
                this.submitting = {{ $includedDeploy ? 'true' : 'false' }};
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

                if (data.version_picker?.show && data.version_picker.value && !this.selectedVersion) {
                    this.selectedVersion = data.version_picker.value;
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
