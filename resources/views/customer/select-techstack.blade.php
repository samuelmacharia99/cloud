@extends('layouts.customer')

@section('title', 'Select Your Techstack')

@section('content')
<div class="space-y-6" x-data="techstackSelector()" @keydown.escape="showStackModal = false">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Deploy Your Application</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Choose your app type, then configure backend roles and database. All new deployments use <strong>application hosting</strong>.</p>
        </div>
        <a href="{{ route('customer.cart.index') }}" class="relative">
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

    <!-- Language Selection Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($languages as $language)
            <button
                type="button"
                @click="selectLanguageAndShowModal({{ $language->id }})"
                class="p-4 border-2 rounded-lg transition-all text-left hover:shadow-lg"
                :class="selectedLanguage.id === {{ $language->id }}
                    ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800 shadow-md'
                    : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:border-blue-400 dark:hover:border-blue-600'"
            >
                <div class="flex items-start gap-3 mb-2">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200/80 dark:border-slate-700">
                        <x-tech-stack-icon :slug="$language->slug" class="w-8 h-8" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2">
                            <span class="font-semibold text-slate-900 dark:text-white">{{ $language->name }}</span>
                            <svg x-show="selectedLanguage.id === {{ $language->id }}" class="w-5 h-5 text-blue-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="flex gap-2 mt-1">
                            <span class="inline-block text-xs bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200 px-2 py-0.5 rounded-full">Application hosting</span>
                        </div>
                    </div>
                </div>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-3">{{ $language->description }}</p>
                @if($language->versions && count($language->versions) > 0)
                    <div class="flex flex-wrap gap-1">
                        @foreach($language->versions as $version)
                            <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 text-xs rounded text-slate-700 dark:text-slate-300 whitespace-nowrap">v{{ $version }}</span>
                        @endforeach
                    </div>
                @endif
            </button>
        @endforeach
    </div>

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
            class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 max-w-2xl w-full max-h-[90vh] overflow-y-auto"
        >
            <div class="sticky top-0 flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 z-10">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Configure your stack</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                        Compatible options for <span class="font-semibold" x-text="selectedLanguage.name"></span>
                    </p>
                </div>
                <button
                    @click="showStackModal = false"
                    type="button"
                    class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
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
                        <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                            <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide">Backend</p>
                            <p class="font-semibold text-slate-900 dark:text-white" x-text="selectedLanguage.name"></p>
                        </div>

                        <template x-if="stackOptions.framework.show">
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white mb-2">
                                    Framework
                                    <span class="text-red-500" x-show="stackOptions.framework.required">*</span>
                                </p>
                                <div class="space-y-2">
                                    <template x-for="option in stackOptions.framework.options" :key="option.value">
                                        <label class="block p-3 border-2 rounded-lg cursor-pointer transition-all"
                                            :class="selectedFramework === option.value
                                                ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800'
                                                : 'border-slate-200 dark:border-slate-700 hover:border-blue-400 dark:hover:border-blue-600'"
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
                                <p class="text-sm font-semibold text-slate-900 dark:text-white mb-2">
                                    Frontend
                                    <span class="text-red-500" x-show="stackOptions.frontend.required">*</span>
                                </p>
                                <div class="space-y-2">
                                    <template x-for="option in stackOptions.frontend.options" :key="option.value">
                                        <label class="block p-3 border-2 rounded-lg cursor-pointer transition-all"
                                            :class="selectedFrontend === option.value
                                                ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800'
                                                : 'border-slate-200 dark:border-slate-700 hover:border-blue-400 dark:hover:border-blue-600'"
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
                                <p class="text-sm font-semibold text-slate-900 dark:text-white mb-2">
                                    Database
                                    <span class="text-red-500" x-show="stackOptions.database.required">*</span>
                                </p>
                                <div class="space-y-2">
                                    <template x-if="stackOptions.database.allow_none">
                                        <label class="block p-3 border-2 rounded-lg cursor-pointer transition-all"
                                            :class="selectedDatabaseId === ''
                                                ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800'
                                                : 'border-slate-200 dark:border-slate-700 hover:border-blue-400 dark:hover:border-blue-600'"
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
                                        <label class="block p-3 border-2 rounded-lg cursor-pointer transition-all"
                                            :class="String(selectedDatabaseId) === String(db.id)
                                                ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800'
                                                : 'border-slate-200 dark:border-slate-700 hover:border-blue-400 dark:hover:border-blue-600'"
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

            <div class="border-t border-slate-200 dark:border-slate-800 p-6 space-y-4" x-show="!loading && stackOptions">
                <div class="p-4 rounded-lg bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700">
                    <p class="font-semibold text-purple-900 dark:text-purple-200">Application hosting</p>
                    <p class="text-sm mt-1 text-purple-700 dark:text-purple-300"
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
                            class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg font-semibold transition"
                        >
                            Continue to Packages
                        </button>
                    </form>
                    <button
                        @click="closeModal()"
                        type="button"
                        class="px-6 py-3 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold hover:bg-slate-100 dark:hover:bg-slate-800 transition"
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
