<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['name', 'type' => 'text', 'label' => null, 'placeholder' => '', 'required' => false, 'autofocus' => false, 'autocomplete' => null, 'value' => null, 'error' => null]));

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

foreach (array_filter((['name', 'type' => 'text', 'label' => null, 'placeholder' => '', 'required' => false, 'autofocus' => false, 'autocomplete' => null, 'value' => null, 'error' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<div>
    <?php if($label): ?>
        <label for="<?php echo e($name); ?>" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
            <?php echo e($label); ?>

            <?php if($required): ?>
                <span class="text-red-500">*</span>
            <?php endif; ?>
        </label>
    <?php endif; ?>

    <input
        type="<?php echo e($type); ?>"
        id="<?php echo e($name); ?>"
        name="<?php echo e($name); ?>"
        value="<?php echo e(old($name, $value)); ?>"
        placeholder="<?php echo e($placeholder); ?>"
        <?php if($required): ?> required <?php endif; ?>
        <?php if($autofocus): ?> autofocus <?php endif; ?>
        <?php if($autocomplete): ?> autocomplete="<?php echo e($autocomplete); ?>" <?php endif; ?>
        class="auth-input"
        <?php echo e($attributes); ?>

    />

    <?php if($error): ?>
        <p class="mt-1 text-xs text-red-600 dark:text-red-400"><?php echo e($error); ?></p>
    <?php endif; ?>
</div>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/components/auth-input.blade.php ENDPATH**/ ?>