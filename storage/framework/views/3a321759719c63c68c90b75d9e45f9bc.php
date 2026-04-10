<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['label', 'name', 'options' => [], 'value' => null, 'placeholder' => 'Select an option...', 'required' => false, 'disabled' => false, 'help' => null, 'error' => null, 'useOld' => true]));

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

foreach (array_filter((['label', 'name', 'options' => [], 'value' => null, 'placeholder' => 'Select an option...', 'required' => false, 'disabled' => false, 'help' => null, 'error' => null, 'useOld' => true]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<?php
    $hasError = $errors->has($name) || $error;
    $errorMessage = $error ?? $errors->first($name);
    $selectedValue = $useOld ? old($name, $value) : $value;
?>

<div class="space-y-2">
    <?php if($label): ?>
        <label for="<?php echo e($name); ?>" class="block text-sm font-medium text-slate-700 dark:text-slate-300">
            <?php echo e($label); ?>

            <?php if($required): ?>
                <span class="text-red-500">*</span>
            <?php endif; ?>
        </label>
    <?php endif; ?>

    <select
        name="<?php echo e($name); ?>"
        id="<?php echo e($name); ?>"
        <?php if($required): ?> required <?php endif; ?>
        <?php if($disabled): ?> disabled <?php endif; ?>
        <?php echo e($attributes->merge([
            'class' => 'block w-full px-4 py-2.5 rounded-lg border transition-colors ' .
            ($hasError
                ? 'border-red-300 bg-red-50 text-red-900 focus:border-red-500 focus:ring-red-500 dark:border-red-700 dark:bg-red-900/20 dark:text-red-100 dark:focus:border-red-500 dark:focus:ring-red-500'
                : 'border-slate-300 bg-white text-slate-900 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-white dark:focus:border-blue-400 dark:focus:ring-blue-400'
            ) .
            ($disabled ? ' opacity-50 cursor-not-allowed' : '')
        ])); ?>

    >
        <?php if($placeholder): ?>
            <option value=""><?php echo e($placeholder); ?></option>
        <?php endif; ?>
        <?php $__currentLoopData = $options; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <option value="<?php echo e($key); ?>" <?php if($selectedValue == $key): ?> selected <?php endif; ?>>
                <?php echo e($label); ?>

            </option>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </select>

    <?php if($hasError): ?>
        <p class="text-sm font-medium text-red-600 dark:text-red-400"><?php echo e($errorMessage); ?></p>
    <?php elseif($help): ?>
        <p class="text-sm text-slate-500 dark:text-slate-400"><?php echo e($help); ?></p>
    <?php endif; ?>
</div>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/components/form-select.blade.php ENDPATH**/ ?>