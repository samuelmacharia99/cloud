@php
    $value = $service->status->value;
    $pill = match ($value) {
        'active' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300',
        'pending' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300',
        'provisioning' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
        'suspended' => 'bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300',
        'terminated', 'failed' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
        default => 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400',
    };
@endphp
<span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold whitespace-nowrap {{ $pill }}">
    {{ ucfirst($value) }}
</span>
