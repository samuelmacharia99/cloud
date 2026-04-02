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
$styles = [];

if ($type === 'service') {
    $styles = [
        'active' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-200',
        'suspended' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-200',
        'terminated' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-200',
        'cancelled' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
    ];
} elseif ($type === 'invoice') {
    $styles = [
        'unpaid' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-200',
        'paid' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-200',
        'overdue' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-200',
        'cancelled' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
    ];
} elseif ($type === 'ticket') {
    $styles = [
        'open' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-200',
        'in_progress' => 'bg-violet-100 dark:bg-violet-950 text-violet-700 dark:text-violet-200',
        'on_hold' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-200',
        'closed' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
    ];
} elseif ($type === 'priority') {
    $styles = [
        'low' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
        'medium' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-200',
        'high' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-200',
        'urgent' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-200',
    ];
}
?>

<span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?php echo e($styles[strtolower($status)] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'); ?>">
    <?php echo e(ucfirst(str_replace('_', ' ', $status))); ?>

</span>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/components/status-badge.blade.php ENDPATH**/ ?>