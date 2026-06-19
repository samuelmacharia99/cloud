@props(['warning'])

<div {{ $attributes->merge(['class' => 'ui-card p-4 border-amber-200 dark:border-amber-800 bg-amber-50/80 dark:bg-amber-950/30 flex flex-wrap items-center justify-between gap-3']) }}>
    <div class="min-w-0">
        <p class="text-sm font-semibold text-amber-950 dark:text-amber-100">Plan upgrade recommended</p>
        <p class="text-sm text-amber-900 dark:text-amber-200 mt-1">
            <strong>{{ $warning['service_name'] }}</strong> is at
            <strong>{{ number_format(collect($warning['metrics_at_risk'])->max(fn ($metric) => $metric['percent'] ?? 0), 0) }}%</strong>
            of your
            {{ collect($warning['metrics_at_risk'])->keys()->map(fn ($metric) => strtolower($metric === 'database' ? 'database' : ($metric === 'bandwidth' ? 'bandwidth' : 'storage')))->implode(' and ') }}
            limit.
            @if (!empty($warning['recommended_upgrade']))
                Upgrade to <strong>{{ $warning['recommended_upgrade']->name }}</strong> to keep growing without interruption.
            @else
                Upgrade to the next plan to keep growing without interruption.
            @endif
        </p>
    </div>
    <a href="{{ route('customer.services.upgrade', $warning['service']) }}" class="btn-sm btn-primary shrink-0">
        Upgrade plan
    </a>
</div>
