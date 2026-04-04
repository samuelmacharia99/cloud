<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['name', 'label' => null, 'placeholder' => '', 'required' => false, 'autocomplete' => 'current-password', 'value' => null, 'error' => null, 'toggleId' => null]));

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

foreach (array_filter((['name', 'label' => null, 'placeholder' => '', 'required' => false, 'autocomplete' => 'current-password', 'value' => null, 'error' => null, 'toggleId' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<?php
    $toggleId = $toggleId ?? $name . '_toggle';
?>

<div x-data="{ show: false }" <?php echo e($attributes); ?>>
    <?php if($label): ?>
        <label for="<?php echo e($name); ?>" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
            <?php echo e($label); ?>

            <?php if($required): ?>
                <span class="text-red-500">*</span>
            <?php endif; ?>
        </label>
    <?php endif; ?>

    <div class="relative">
        <input
            :type="show ? 'text' : 'password'"
            id="<?php echo e($name); ?>"
            name="<?php echo e($name); ?>"
            value="<?php echo e(old($name, $value)); ?>"
            placeholder="<?php echo e($placeholder); ?>"
            <?php if($required): ?> required <?php endif; ?>
            autocomplete="<?php echo e($autocomplete); ?>"
            class="auth-input pr-10"
        />
        <button
            type="button"
            @click="show = !show"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition"
            aria-label="Toggle password visibility"
        >
            <svg v-if="!show" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
            </svg>
            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-4.753 4.753m5.596-3.856a3.375 3.375 0 01-4.753 4.753M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 9.172M15.828 15.828m-7.071-7.071L15.828 15.828M9.172 15.828L15.828 9.172"></path>
            </svg>
        </button>
    </div>

    <?php if($error): ?>
        <p class="mt-1 text-xs text-red-600 dark:text-red-400"><?php echo e($error); ?></p>
    <?php endif; ?>
</div>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/components/auth-password-input.blade.php ENDPATH**/ ?>