<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['method', 'class' => 'w-5 h-5']));

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

foreach (array_filter((['method', 'class' => 'w-5 h-5']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<?php
    if (is_string($method)) {
        $method = \App\Enums\PaymentMethod::tryFrom($method);
    }
    if (!$method) {
        return;
    }
?>

<?php switch($method->value):
    case ('mpesa'): ?>
        <svg <?php echo e($attributes->merge(['class' => $class])); ?> fill="currentColor" viewBox="0 0 24 24">
            <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-7-7Z"/>
            <path d="M13 2v7h7" stroke="currentColor" stroke-width="2" fill="none"/>
            <text x="12" y="16" text-anchor="middle" font-size="6" font-weight="bold" fill="white">M</text>
        </svg>
        <?php break; ?>

    <?php case ('card'): ?>
        <svg <?php echo e($attributes->merge(['class' => $class])); ?> fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
        </svg>
        <?php break; ?>

    <?php case ('bank_transfer'): ?>
        <svg <?php echo e($attributes->merge(['class' => $class])); ?> fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v14a2 2 0 01-2 2zM3 7h18M7 11h.01M11 11h.01M15 11h.01M7 15h.01M11 15h.01M15 15h.01"/>
        </svg>
        <?php break; ?>

    <?php case ('wallet'): ?>
        <svg <?php echo e($attributes->merge(['class' => $class])); ?> fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2.25a.75.75 0 01.75.75v2.5a.75.75 0 01-.75.75H5a2 2 0 002 2h12a2 2 0 002-2v-5a2 2 0 00-2-2h-.75a.75.75 0 00-.75.75v2.5a.75.75 0 00.75.75H17z"/>
        </svg>
        <?php break; ?>

    <?php case ('manual'): ?>
        <svg <?php echo e($attributes->merge(['class' => $class])); ?> fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        <?php break; ?>

    <?php default: ?>
        <svg <?php echo e($attributes->merge(['class' => $class])); ?> fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
<?php endswitch; ?>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/components/payment-method-icon.blade.php ENDPATH**/ ?>