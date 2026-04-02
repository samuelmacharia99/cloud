@props(['status', 'type' => 'service'])

@php
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
@endphp

<span class="inline-block px-3 py-1 rounded-full text-xs font-medium {{ $styles[strtolower($status)] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' }}">
    {{ ucfirst(str_replace('_', ' ', $status)) }}
</span>
