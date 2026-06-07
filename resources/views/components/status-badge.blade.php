@props(['status', 'type' => 'service', 'dot' => true])

@php
    $enumClass = match($type) {
        'payment' => 'App\Enums\PaymentStatus',
        'invoice' => 'App\Enums\InvoiceStatus',
        'service' => 'App\Enums\ServiceStatus',
        default => null,
    };

    if (!$enumClass) {
        $styles = [
            'ticket' => [
                'open' => ['pill' => 'bg-blue-100 dark:bg-blue-950/60 text-blue-700 dark:text-blue-200', 'dot' => 'bg-blue-500'],
                'in_progress' => ['pill' => 'bg-violet-100 dark:bg-violet-950/60 text-violet-700 dark:text-violet-200', 'dot' => 'bg-violet-500'],
                'on_hold' => ['pill' => 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-200', 'dot' => 'bg-amber-500'],
                'closed' => ['pill' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300', 'dot' => 'bg-slate-400'],
            ],
            'priority' => [
                'low' => ['pill' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300', 'dot' => 'bg-slate-400'],
                'medium' => ['pill' => 'bg-blue-100 dark:bg-blue-950/60 text-blue-700 dark:text-blue-200', 'dot' => 'bg-blue-500'],
                'high' => ['pill' => 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-200', 'dot' => 'bg-amber-500'],
                'urgent' => ['pill' => 'bg-red-100 dark:bg-red-950/60 text-red-700 dark:text-red-200', 'dot' => 'bg-red-500'],
            ],
        ];

        $statusStyles = $styles[$type] ?? [];
        $style = $statusStyles[strtolower($status)] ?? ['pill' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300', 'dot' => 'bg-slate-400'];
        $label = ucfirst(str_replace('_', ' ', $status));
    } else {
        if (is_object($status) && method_exists($status, 'badge')) {
            $enum = $status;
        } else {
            $enum = $enumClass::tryFrom($status);
            if (!$enum) {
                return;
            }
        }

        $colorMap = [
            'success' => ['pill' => 'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-200', 'dot' => 'bg-emerald-500'],
            'danger' => ['pill' => 'bg-red-100 dark:bg-red-950/60 text-red-700 dark:text-red-200', 'dot' => 'bg-red-500'],
            'warning' => ['pill' => 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-200', 'dot' => 'bg-amber-500'],
            'info' => ['pill' => 'bg-brand-100 dark:bg-brand-950/60 text-brand-700 dark:text-brand-200', 'dot' => 'bg-brand-500'],
            'secondary' => ['pill' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300', 'dot' => 'bg-slate-400'],
        ];

        $badge = $enum->badge();
        $style = $colorMap[$badge] ?? $colorMap['secondary'];
        $label = $enum->label();
    }
@endphp

<span {{ $attributes->merge(['class' => "status-pill {$style['pill']}"]) }}>
    @if($dot)
        <span class="status-pill-dot {{ $style['dot'] }}"></span>
    @endif
    {{ $label }}
</span>
