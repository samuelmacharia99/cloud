<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['status', 'type' => 'service']));

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

foreach (array_filter((['status', 'type' => 'service']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<?php
    $enumClass = match($type) {
        'payment' => 'App\Enums\PaymentStatus',
        'invoice' => 'App\Enums\InvoiceStatus',
        'service' => 'App\Enums\ServiceStatus',
        default => null,
    };

    if (!$enumClass) {
        // Fallback for non-enum types
        $styles = [
            'ticket' => [
                'open' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-200',
                'in_progress' => 'bg-violet-100 dark:bg-violet-950 text-violet-700 dark:text-violet-200',
                'on_hold' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-200',
                'closed' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
            ],
            'priority' => [
                'low' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
                'medium' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-200',
                'high' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-200',
                'urgent' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-200',
            ],
        ];

        $statusStyles = $styles[$type] ?? [];
        $style = $statusStyles[strtolower($status)] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300';
        $label = ucfirst(str_replace('_', ' ', $status));
    } else {
        // Handle both enum instances and string/int values
        if (is_object($status) && method_exists($status, 'badge')) {
            $enum = $status;
        } else {
            $enum = $enumClass::tryFrom($status);
            if (!$enum) {
                return;
            }
        }

        $colorMap = [
            'success' => 'bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-200',
            'danger' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-200',
            'warning' => 'bg-yellow-100 dark:bg-yellow-950 text-yellow-700 dark:text-yellow-200',
            'info' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-200',
            'secondary' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
        ];

        $badge = $enum->badge();
        $style = $colorMap[$badge] ?? $colorMap['secondary'];
        $label = $enum->label();
    }
?>

<span <?php echo e($attributes->merge(['class' => "inline-block px-3 py-1 rounded-full text-xs font-medium {$style}"])); ?>>
    <?php echo e($label); ?>

</span>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/components/status-badge.blade.php ENDPATH**/ ?>