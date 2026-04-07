<?php $__env->startSection('title', 'Select Your Techstack'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6" x-data="techstackSelector()" @keydown.escape="showDatabaseModal = false">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Deploy Your Application</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Select your programming language to get started</p>
        </div>
        <a href="<?php echo e(route('customer.cart.index')); ?>" class="relative">
            <svg class="w-6 h-6 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            <?php if($cartCount > 0): ?>
                <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center"><?php echo e($cartCount); ?></span>
            <?php endif; ?>
        </a>
    </div>

    <!-- Language Selection Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php $__currentLoopData = $languages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $language): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <button
                type="button"
                @click="selectLanguageAndShowModal($event, <?php echo e($language->id); ?>)"
                class="p-4 border-2 rounded-lg transition-all text-left hover:shadow-lg"
                :class="selectedLanguage.id === <?php echo e($language->id); ?>

                    ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800 shadow-md'
                    : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:border-blue-400 dark:hover:border-blue-600'"
            >
                <div class="flex items-start justify-between mb-2">
                    <span class="font-semibold text-slate-900 dark:text-white"><?php echo e($language->name); ?></span>
                    <svg v-if="selectedLanguage.id === <?php echo e($language->id); ?>" class="w-5 h-5 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                </div>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-3"><?php echo e($language->description); ?></p>
                <?php if($language->versions && count($language->versions) > 0): ?>
                    <div class="flex flex-wrap gap-1">
                        <?php $__currentLoopData = $language->versions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $version): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 text-xs rounded text-slate-700 dark:text-slate-300 whitespace-nowrap">v<?php echo e($version); ?></span>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </div>
                <?php endif; ?>
            </button>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    <!-- Alternative: Browse Services -->
    <div class="flex justify-center pt-4">
        <a href="<?php echo e(route('customer.browse-services')); ?>" class="px-6 py-3 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold hover:bg-slate-100 dark:hover:bg-slate-800 transition">
            Browse All Services
        </a>
    </div>

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
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Select Database</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                        Choose a database for <span class="font-semibold" x-text="selectedLanguage.name"></span>
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

            <!-- Database Options -->
            <div class="p-6 space-y-3">
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
                                        @change="selectDatabase(db, true)"
                                        class="mt-1"
                                    >
                                    <div class="flex-1">
                                        <span class="font-semibold text-slate-900 dark:text-white" x-text="db.name"></span>
                                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1" x-text="'Type: ' + db.type"></p>
                                    </div>
                                    <svg v-if="selectedDatabase.id === db.id" class="w-5 h-5 text-blue-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
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
            <template x-if="selectedDatabase.id">
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
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="language_id" :value="selectedLanguage.id">
                            <input type="hidden" name="database_id" :value="selectedDatabase.id">
                            <button
                                type="submit"
                                class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition"
                            >
                                Continue to Packages
                            </button>
                        </form>
                        <button
                            @click="showDatabaseModal = false"
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
        loading: false,
        confirmTechstackUrl: '<?php echo e(route("customer.confirm-techstack")); ?>',

        selectLanguageAndShowModal(event, languageId) {
            const language = <?php echo json_encode($languages, 15, 512) ?>.find(l => l.id == languageId);
            this.selectedLanguage = language;
            this.selectedDatabase = {};
            this.availableDatabases = [];
            this.showDatabaseModal = true;
            this.loadDatabases(languageId);
        },

        selectDatabase(db) {
            this.selectedDatabase = db;
        },

        async loadDatabases(languageId) {
            this.loading = true;
            try {
                const response = await fetch(`/api/languages/${languageId}/databases`);
                const data = await response.json();
                this.availableDatabases = data.databases;
            } catch (error) {
                console.error('Error loading databases:', error);
            } finally {
                this.loading = false;
            }
        },

        get hostingTypeInfo() {
            const isDirectAdmin = this.selectedLanguage.hosting_type === 'directadmin';
            return {
                emoji: isDirectAdmin ? '🌐' : '🐳',
                label: isDirectAdmin ? 'DirectAdmin Shared Hosting' : 'Container Hosting',
                description: isDirectAdmin
                    ? 'Your application will be deployed to shared hosting with DirectAdmin control panel'
                    : 'Your application will be deployed to a containerized environment with Docker',
                bgClass: isDirectAdmin
                    ? 'bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700'
                    : 'bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700',
                textClass: isDirectAdmin
                    ? 'text-blue-900 dark:text-blue-200'
                    : 'text-purple-900 dark:text-purple-200',
                descClass: isDirectAdmin
                    ? 'text-blue-700 dark:text-blue-300'
                    : 'text-purple-700 dark:text-purple-300',
            };
        },

    };
}
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/customer/select-techstack.blade.php ENDPATH**/ ?>