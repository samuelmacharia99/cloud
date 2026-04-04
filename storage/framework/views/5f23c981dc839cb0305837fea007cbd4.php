<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['method']));

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

foreach (array_filter((['method']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<?php
    // Handle both enum instances and string/int values
    if (!$method instanceof \App\Enums\PaymentMethod) {
        $method = \App\Enums\PaymentMethod::tryFrom($method);
    }

    if (!$method) {
        return;
    }

    $colors = [
        'green' => 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300 border-green-200 dark:border-green-800',
        'blue' => 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800',
        'slate' => 'bg-slate-50 dark:bg-slate-900/20 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-800',
        'purple' => 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-800',
        'amber' => 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800',
    ];

    $iconMap = [
        'phone' => 'phone',
        'credit-card' => 'credit-card',
        'building-2' => 'building',
        'wallet' => 'wallet',
        'check' => 'check',
    ];

    $color = $colors[$method->color()] ?? $colors['slate'];
?>

<span <?php echo e($attributes->merge(['class' => "inline-flex items-center gap-2 px-3 py-1.5 rounded-full border text-sm font-medium {$color}"])); ?>>
    <?php echo $__env->make('components.payment-method-icon', ['method' => $method, 'class' => 'w-4 h-4'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <span><?php echo e($method->label()); ?></span>
</span>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/components/payment-badge.blade.php ENDPATH**/ ?>