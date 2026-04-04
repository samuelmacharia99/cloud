<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['amount', 'currency' => 'KES', 'showSymbol' => true, 'decimals' => 2]));

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

foreach (array_filter((['amount', 'currency' => 'KES', 'showSymbol' => true, 'decimals' => 2]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<?php
    $currencySymbols = [
        'KES' => 'Ksh',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
    ];

    $symbol = $currencySymbols[$currency] ?? $currency;
    $formatted = number_format((float) $amount, $decimals, '.', ',');
?>

<span <?php echo e($attributes->merge(['class' => 'font-medium text-slate-900 dark:text-white'])); ?>>
    <?php if($showSymbol): ?>
        <span class="text-sm text-slate-600 dark:text-slate-400"><?php echo e($symbol); ?></span>
    <?php endif; ?>
    <?php echo e($formatted); ?>

</span>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/components/currency-formatter.blade.php ENDPATH**/ ?>