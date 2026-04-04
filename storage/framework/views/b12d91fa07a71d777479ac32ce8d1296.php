<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['title' => 'Confirm Action', 'message' => 'Are you sure?', 'confirmText' => 'Confirm', 'cancelText' => 'Cancel', 'danger' => false, 'action' => null]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter((['title' => 'Confirm Action', 'message' => 'Are you sure?', 'confirmText' => 'Confirm', 'cancelText' => 'Cancel', 'danger' => false, 'action' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<div
    x-data="{ open: false }"
    class="inline-block"
>
    <!-- Trigger Button -->
    <button
        @click="open = true"
        type="button"
        <?php echo e($attributes->merge([
            'class' => 'inline-flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium transition-colors ' .
            ($danger
                ? 'bg-red-600 hover:bg-red-700 text-white dark:bg-red-700 dark:hover:bg-red-800'
                : 'bg-blue-600 hover:bg-blue-700 text-white dark:bg-blue-700 dark:hover:bg-blue-800'
            )
        ])); ?>

    >
        <?php echo e($slot); ?>

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
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white"><?php echo e($title); ?></h3>
            </div>

            <!-- Body -->
            <div class="px-6 py-4">
                <p class="text-slate-600 dark:text-slate-400"><?php echo e($message); ?></p>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 rounded-b-xl">
                <button
                    type="button"
                    @click="open = false"
                    class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors font-medium"
                >
                    <?php echo e($cancelText); ?>

                </button>

                <?php if($action): ?>
                    <form method="POST" action="<?php echo e($action); ?>" style="display: inline;">
                        <?php echo csrf_field(); ?>
                        <button
                            type="submit"
                            class="<?php echo e($danger ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'); ?> px-4 py-2 rounded-lg text-white transition-colors font-medium"
                        >
                            <?php echo e($confirmText); ?>

                        </button>
                    </form>
                <?php else: ?>
                    <button
                        type="button"
                        @click="open = false"
                        class="<?php echo e($danger ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'); ?> px-4 py-2 rounded-lg text-white transition-colors font-medium"
                    >
                        <?php echo e($confirmText); ?>

                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/components/confirmation-dialog.blade.php ENDPATH**/ ?>