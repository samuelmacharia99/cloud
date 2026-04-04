<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['title', 'description' => null, 'href' => null, 'action_text' => 'View all']));

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

foreach (array_filter((['title', 'description' => null, 'href' => null, 'action_text' => 'View all']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
    <div class="p-6 border-b border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white"><?php echo e($title); ?></h2>
                <?php if($description): ?>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1"><?php echo e($description); ?></p>
                <?php endif; ?>
            </div>
            <?php if($href): ?>
                <a href="<?php echo e($href); ?>" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">
                    <?php echo e($action_text); ?> →
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div>
        <?php echo e($slot); ?>

    </div>
</div>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/components/dashboard-section.blade.php ENDPATH**/ ?>