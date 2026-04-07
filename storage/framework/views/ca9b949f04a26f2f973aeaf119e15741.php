<?php $__env->startSection('title', 'Select Your Techstack'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Select Your Techstack</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Choose your programming language and database</p>
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

    <!-- Selection Form -->
    <form action="<?php echo e(route('customer.confirm-techstack')); ?>" method="POST" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8" x-data="techstackSelector()">
        <?php echo csrf_field(); ?>

        <div class="grid md:grid-cols-2 gap-8">
            <!-- Language Selection -->
            <div>
                <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-4">Programming Language</h2>
                <div class="space-y-3">
                    <?php $__currentLoopData = $languages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $language): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <label class="block p-4 border-2 border-slate-200 dark:border-slate-700 rounded-lg cursor-pointer hover:border-blue-400 dark:hover:border-blue-600 transition" :class="{ 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800': selectedLanguage.id === <?php echo e($language->id); ?> }">
                            <input type="radio" name="language_id" value="<?php echo e($language->id); ?>" @change="selectLanguage($event)" class="mr-3">
                            <span class="font-semibold text-slate-900 dark:text-white"><?php echo e($language->name); ?></span>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1"><?php echo e($language->description); ?></p>
                            <?php if($language->versions && count($language->versions) > 0): ?>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <?php $__currentLoopData = $language->versions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $version): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <span class="px-2 py-1 bg-slate-100 dark:bg-slate-700 text-xs rounded text-slate-700 dark:text-slate-300">v<?php echo e($version); ?></span>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                </div>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </div>
                <?php $__errorArgs = ['language_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="text-red-600 text-sm mt-2"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            <!-- Database Selection -->
            <div>
                <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-4">Database</h2>
                <div class="space-y-3">
                    <template x-if="availableDatabases.length > 0">
                        <div class="space-y-3">
                            <template x-for="db in availableDatabases" :key="db.id">
                                <label class="block p-4 border-2 border-slate-200 dark:border-slate-700 rounded-lg cursor-pointer hover:border-blue-400 dark:hover:border-blue-600 transition" :class="{ 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800': selectedDatabase.id === db.id }">
                                    <input type="radio" name="database_id" :value="db.id" @change="selectDatabase(db)" class="mr-3">
                                    <span class="font-semibold text-slate-900 dark:text-white" x-text="db.name"></span>
                                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1" x-text="'Type: ' + db.type"></p>
                                </label>
                            </template>
                        </div>
                    </template>
                    <template x-if="availableDatabases.length === 0 && selectedLanguage.id">
                        <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg">
                            <p class="text-sm text-yellow-700 dark:text-yellow-200">Please select a language first</p>
                        </div>
                    </template>
                    <template x-if="!selectedLanguage.id">
                        <div class="p-4 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg">
                            <p class="text-sm text-gray-600 dark:text-gray-400">Select a language to see available databases</p>
                        </div>
                    </template>
                </div>
                <?php $__errorArgs = ['database_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="text-red-600 text-sm mt-2"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>
        </div>

        <!-- Routing Info -->
        <template x-if="selectedLanguage.id && selectedDatabase.id">
            <div class="mt-8 p-6 rounded-lg" :class="hostingTypeInfo.bgClass">
                <div class="flex items-start gap-3">
                    <svg class="w-6 h-6 flex-shrink-0 mt-0.5" :class="hostingTypeInfo.iconClass" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 5v8a2 2 0 01-2 2h-5l-5 4v-4H4a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2z" clip-rule="evenodd"/>
                    </svg>
                    <div>
                        <p class="font-semibold text-sm" :class="hostingTypeInfo.textClass" x-text="hostingTypeInfo.label"></p>
                        <p class="text-sm mt-1" :class="hostingTypeInfo.descClass" x-text="hostingTypeInfo.description"></p>
                    </div>
                </div>
            </div>
        </template>

        <!-- Submit Button -->
        <div class="mt-8 flex gap-4">
            <button type="submit" :disabled="!selectedLanguage.id || !selectedDatabase.id" class="flex-1 px-6 py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white rounded-lg font-semibold transition">
                Continue
            </button>
            <a href="<?php echo e(route('customer.browse-services')); ?>" class="px-6 py-3 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                Browse All Services
            </a>
        </div>
    </form>
</div>

<script>
function techstackSelector() {
    return {
        selectedLanguage: {},
        selectedDatabase: {},
        availableDatabases: [],
        loading: false,

        selectLanguage(event) {
            const languageId = event.target.value;
            const language = <?php echo json_encode($languages, 15, 512) ?>.find(l => l.id == languageId);
            this.selectedLanguage = language;
            this.selectedDatabase = {};
            this.availableDatabases = [];
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
                label: isDirectAdmin ? '🌐 DirectAdmin Shared Hosting' : '🐳 Container Hosting',
                description: isDirectAdmin
                    ? 'Your application will be deployed to shared hosting with DirectAdmin control panel'
                    : 'Your application will be deployed to a containerized environment with Docker',
                bgClass: isDirectAdmin
                    ? 'bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700'
                    : 'bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700',
                iconClass: isDirectAdmin
                    ? 'text-blue-600 dark:text-blue-400'
                    : 'text-purple-600 dark:text-purple-400',
                textClass: isDirectAdmin
                    ? 'text-blue-900 dark:text-blue-200'
                    : 'text-purple-900 dark:text-purple-200',
                descClass: isDirectAdmin
                    ? 'text-blue-700 dark:text-blue-300'
                    : 'text-purple-700 dark:text-purple-300',
            };
        }
    };
}
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/customer/select-techstack.blade.php ENDPATH**/ ?>