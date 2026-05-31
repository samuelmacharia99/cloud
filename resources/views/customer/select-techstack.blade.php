@extends('layouts.customer')

@section('title', 'Select Your Techstack')

@section('content')
<div class="space-y-6" x-data="techstackSelector()" @keydown.escape="showDatabaseModal = false">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Deploy Your Application</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Select your programming language to get started</p>
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
                <div class="flex items-start justify-between mb-2">
                    <div class="flex-1">
                        <span class="font-semibold text-slate-900 dark:text-white">{{ $language->name }}</span>
                        <div class="flex gap-2 mt-1">
                            @if($language->slug === 'laravel')
                                <span class="inline-block text-xs bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-200 px-2 py-0.5 rounded-full">Shared or Container</span>
                            @elseif($language->hosting_type === 'directadmin')
                                <span class="inline-block text-xs bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200 px-2 py-0.5 rounded-full">Shared Hosting</span>
                            @else
                                <span class="inline-block text-xs bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200 px-2 py-0.5 rounded-full">Container</span>
                            @endif
                        </div>
                    </div>
                    <svg x-show="selectedLanguage.id === {{ $language->id }}" class="w-5 h-5 text-blue-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
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
    <form id="skip-db-form" method="POST" action="{{ route('customer.confirm-techstack') }}" class="hidden">
        @csrf
        <input type="hidden" id="skip-db-form-language" name="language_id" value="">
        <input type="hidden" name="database_id" value="">
    </form>

    <!-- Database Selection Modal -->
    <div
        x-show="showDatabaseModal"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        class="fixed inset-0 bg-black/50 dark:bg-black/70 flex items-center justify-center p-4 z-50"
        @click.self="showDatabaseModal = false"
    >
        <div
            @click.stop
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 max-w-2xl w-full max-h-[90vh] overflow-y-auto"
        >
            <!-- Modal Header -->
            <div class="sticky top-0 flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white"
                        x-text="modalStep === 'hosting' ? 'Choose Hosting Platform' : 'Select Database'"></h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                        <span x-show="modalStep === 'hosting'">
                            How should <span class="font-semibold" x-text="selectedLanguage.name"></span> be deployed?
                        </span>
                        <span x-show="modalStep === 'database'">
                            Choose a database for <span class="font-semibold" x-text="selectedLanguage.name"></span>
                            on <span class="font-semibold" x-text="deploymentPlatformLabel"></span>
                        </span>
                    </p>
                </div>
                <button
                    @click="showDatabaseModal = false"
                    class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Laravel hosting platform step -->
            <div class="p-6 space-y-4" x-show="modalStep === 'hosting'">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <button type="button" @click="selectDeploymentPlatform('container')"
                        class="relative p-5 border-2 rounded-xl text-left transition-all"
                        :class="deploymentPlatform === 'container'
                            ? 'border-purple-600 dark:border-purple-500 bg-purple-50 dark:bg-purple-950/30 shadow-md'
                            : 'border-slate-200 dark:border-slate-700 hover:border-purple-400 dark:hover:border-purple-600'">
                        <span class="absolute top-3 right-3 text-xs font-semibold bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200 px-2 py-1 rounded-full">Recommended</span>
                        <div class="text-2xl mb-2">🐳</div>
                        <p class="font-semibold text-slate-900 dark:text-white">Container Hosting</p>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2">
                            Docker-based Laravel runtime with flexible PHP versions and isolated resources.
                        </p>
                    </button>

                    <button type="button" @click="selectDeploymentPlatform('shared')"
                        class="p-5 border-2 rounded-xl text-left transition-all"
                        :class="deploymentPlatform === 'shared'
                            ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-blue-950/30 shadow-md'
                            : 'border-slate-200 dark:border-slate-700 hover:border-blue-400 dark:hover:border-blue-600'">
                        <div class="text-2xl mb-2">🌐</div>
                        <p class="font-semibold text-slate-900 dark:text-white">Shared Hosting</p>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2">
                            DirectAdmin shared hosting with cPanel-style management and bundled MySQL.
                        </p>
                    </button>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" @click="continueToDatabaseStep()"
                        class="flex-1 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                        Continue
                    </button>
                    <button type="button" @click="closeModal()"
                        class="px-6 py-3 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                        Back
                    </button>
                </div>
            </div>

            <!-- Database Options -->
            <div class="p-6 space-y-3" x-show="modalStep === 'database'">
                <template x-if="availableDatabases.length > 0">
                    <div class="space-y-3">
                        <template x-for="db in availableDatabases" :key="db.id">
                            <label class="block p-4 border-2 rounded-lg cursor-pointer transition-all"
                                :class="selectedDatabase.id === db.id
                                    ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800'
                                    : 'border-slate-200 dark:border-slate-700 hover:border-blue-400 dark:hover:border-blue-600'"
                            >
                                <div class="flex items-start gap-3">
                                    <input
                                        type="radio"
                                        name="database_id"
                                        :value="db.id"
                                        @change="selectDatabase(db)"
                                        class="mt-1"
                                    >
                                    <div class="flex-1">
                                        <span class="font-semibold text-slate-900 dark:text-white" x-text="db.name"></span>
                                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1" x-text="'Type: ' + db.type"></p>
                                    </div>
                                    <svg x-show="selectedDatabase.id === db.id" class="w-5 h-5 text-blue-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </label>
                        </template>
                    </div>
                </template>
                <template x-if="availableDatabases.length === 0 && loading">
                    <div class="text-center py-8">
                        <div class="inline-block animate-spin">
                            <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 mt-2">Loading databases...</p>
                    </div>
                </template>
            </div>

            <!-- Hosting Info -->
            <template x-if="selectedDatabase.id && modalStep === 'database'">
                <div class="border-t border-slate-200 dark:border-slate-800 p-6 space-y-4">
                    <!-- Hosting Type Info -->
                    <div class="p-4 rounded-lg" :class="hostingTypeInfo.bgClass">
                        <div class="flex items-start gap-3">
                            <span class="text-2xl" x-text="hostingTypeInfo.emoji"></span>
                            <div>
                                <p class="font-semibold" :class="hostingTypeInfo.textClass" x-text="hostingTypeInfo.label"></p>
                                <p class="text-sm mt-1" :class="hostingTypeInfo.descClass" x-text="hostingTypeInfo.description"></p>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex gap-3 pt-2">
                        <form :action="confirmTechstackUrl" method="POST" class="flex-1">
                            @csrf
                            <input type="hidden" name="language_id" :value="selectedLanguage.id">
                            <input type="hidden" name="database_id" :value="selectedDatabase.id">
                            <template x-if="isLaravelSelection">
                                <input type="hidden" name="deployment_platform" :value="deploymentPlatform">
                            </template>
                            <button
                                type="submit"
                                class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition"
                            >
                                Continue to Packages
                            </button>
                        </form>
                        <button
                            @click="goBackFromDatabaseStep()"
                            type="button"
                            class="px-6 py-3 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                        >
                            Back
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function techstackSelector() {
    return {
        selectedLanguage: {},
        selectedDatabase: {},
        availableDatabases: [],
        showDatabaseModal: false,
        modalStep: 'database',
        deploymentPlatform: 'container',
        loading: false,
        confirmTechstackUrl: '{{ route("customer.confirm-techstack") }}',

        get isLaravelSelection() {
            return this.selectedLanguage.slug === 'laravel';
        },

        get deploymentPlatformLabel() {
            return this.deploymentPlatform === 'shared' ? 'Shared Hosting' : 'Container Hosting';
        },

        selectLanguageAndShowModal(languageId) {
            const language = @json($languages).find(l => l.id == languageId);
            this.selectedLanguage = language;
            this.selectedDatabase = {};
            this.availableDatabases = [];
            this.deploymentPlatform = 'container';

            // Skip database modal for static sites
            if (language.slug === 'static-site') {
                this.$nextTick(() => {
                    document.getElementById('skip-db-form-language').value = languageId;
                    document.getElementById('skip-db-form').submit();
                });
                return;
            }

            this.modalStep = language.slug === 'laravel' ? 'hosting' : 'database';
            this.showDatabaseModal = true;

            if (this.modalStep === 'database') {
                this.loadDatabases(languageId);
            }
        },

        selectDeploymentPlatform(platform) {
            this.deploymentPlatform = platform;
        },

        continueToDatabaseStep() {
            this.modalStep = 'database';
            this.selectedDatabase = {};
            this.loadDatabases(this.selectedLanguage.id);
        },

        goBackFromDatabaseStep() {
            if (this.isLaravelSelection) {
                this.modalStep = 'hosting';
                this.selectedDatabase = {};
                this.availableDatabases = [];
                return;
            }

            this.closeModal();
        },

        closeModal() {
            this.showDatabaseModal = false;
            this.modalStep = 'database';
        },

        selectDatabase(db) {
            this.selectedDatabase = db;
        },

        async loadDatabases(languageId) {
            this.loading = true;
            try {
                let url = `/api/languages/${languageId}/databases`;
                if (this.isLaravelSelection) {
                    url += `?deployment_platform=${this.deploymentPlatform}`;
                }

                const response = await fetch(url);
                if (!response.ok) {
                    throw new Error('Failed to load databases');
                }

                const data = await response.json();
                this.availableDatabases = data.databases;
            } catch (error) {
                console.error('Error loading databases:', error);
            } finally {
                this.loading = false;
            }
        },

        get hostingTypeInfo() {
            const isShared = this.isLaravelSelection
                ? this.deploymentPlatform === 'shared'
                : this.selectedLanguage.hosting_type === 'directadmin';

            return {
                emoji: isShared ? '🌐' : '🐳',
                label: isShared ? 'DirectAdmin Shared Hosting' : 'Container Hosting',
                description: isShared
                    ? 'Your Laravel app will be deployed to shared hosting with DirectAdmin control panel'
                    : 'Your Laravel app will run in a containerized environment with Docker',
                bgClass: isShared
                    ? 'bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700'
                    : 'bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700',
                textClass: isShared
                    ? 'text-blue-900 dark:text-blue-200'
                    : 'text-purple-900 dark:text-purple-200',
                descClass: isShared
                    ? 'text-blue-700 dark:text-blue-300'
                    : 'text-purple-700 dark:text-purple-300',
            };
        },

    };
}
</script>
@endsection
