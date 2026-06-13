@php
    $limits = $service->product->resource_limits ?? [];
    $ipAddress = $service->service_meta['ip_address'] ?? $service->service_meta['ip'] ?? null;
    $chosenOs = $service->service_meta['operating_system'] ?? null;
    $chosenIps = $service->service_meta['ip_count'] ?? null;
    $osLabels = config('server_options.linux_distributions', []);
    $isVps = $service->product->type === 'vps';
@endphp

<article class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 hover:shadow-md transition-shadow">
    <div class="flex items-center justify-between gap-3 mb-4">
        <span @class([
            'inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-full',
            'bg-blue-50 dark:bg-blue-950/50 text-blue-700 dark:text-blue-300' => $isVps,
            'bg-indigo-50 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-300' => ! $isVps,
        ])>
            {{ App\Models\Product::typeLabel($service->product->type) }}
        </span>
        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-400">
            <span class="w-2 h-2 rounded-full" style="background-color: @switch($service->status->value) @case('active') rgb(16, 185, 129) @break @case('pending') rgb(59, 130, 246) @break @case('provisioning') rgb(245, 158, 11) @break @case('suspended') rgb(249, 115, 22) @break @case('terminated') @case('failed') rgb(239, 68, 68) @break @default rgb(107, 114, 128) @endswitch"></span>
            {{ $service->status->label() }}
        </span>
    </div>

    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">{{ $service->product->name }}</h3>

    <div class="flex flex-wrap gap-2 mb-4 min-h-[1.5rem]">
        @if($limits['specs'] ?? null)
            <span class="px-2 py-0.5 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded font-mono">{{ $limits['specs'] }}</span>
        @endif
        @if($limits['location'] ?? null)
            <span class="px-2 py-0.5 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded">{{ $limits['location'] }}</span>
        @endif
        @if($chosenOs)
            <span class="px-2 py-0.5 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded">{{ $osLabels[$chosenOs] ?? $chosenOs }}</span>
        @endif
        @if($chosenIps)
            <span class="px-2 py-0.5 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded">{{ $chosenIps }} {{ $chosenIps == 1 ? 'IP' : 'IPs' }}</span>
        @endif
        @if($ipAddress)
            <span class="px-2 py-0.5 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded font-mono">{{ $ipAddress }}</span>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-4 py-4 border-t border-slate-100 dark:border-slate-800 mb-4">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Billing</p>
            <p class="text-sm font-semibold text-slate-900 dark:text-white mt-0.5">{{ ucfirst($service->billing_cycle) }}</p>
        </div>
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Next due</p>
            <p class="text-sm font-semibold text-slate-900 dark:text-white mt-0.5">{{ $service->next_due_date?->format('M d, Y') ?? '—' }}</p>
        </div>
    </div>

    <a href="{{ route('customer.services.show', $service) }}" class="block w-full text-center px-4 py-2.5 bg-slate-900 dark:bg-white text-white dark:text-slate-900 hover:bg-slate-800 dark:hover:bg-slate-100 text-sm font-semibold rounded-lg transition">
        Manage server
    </a>
</article>
