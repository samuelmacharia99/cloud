@php
    $paginator = $paginator ?? collect();
    $empty = $empty ?? 'No services in this group.';
    $showUsage = $showUsage ?? false;
    $usageByServiceId = $usageByServiceId ?? [];
@endphp

@if ($paginator->count())
    <div class="ui-card overflow-hidden">
        <table class="w-full">
            <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                <tr>
                    <th class="px-6 py-4 text-left text-sm font-semibold">Service</th>
                    <th class="px-6 py-4 text-left text-sm font-semibold">Customer</th>
                    <th class="px-6 py-4 text-left text-sm font-semibold">Status</th>
                    @if ($showUsage)
                        <th class="px-6 py-4 text-left text-sm font-semibold">Plan</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Disk</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Transfer (30d)</th>
                    @endif
                    <th class="px-6 py-4 text-right text-sm font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                @foreach ($paginator as $service)
                    @php $usage = $usageByServiceId[$service->id] ?? ['allocated' => null, 'consumed' => null]; @endphp
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                        <td class="px-6 py-4">
                            <p class="font-medium text-slate-900 dark:text-white">{{ $service->name }}</p>
                            <p class="text-xs text-slate-500">{{ $service->customerPlanName() }}</p>
                        </td>
                        <td class="px-6 py-4 text-sm"><x-reseller.customer-link :user="$service->user" /></td>
                        <td class="px-6 py-4"><x-status-badge :status="$service->status" type="service" /></td>
                        @if ($showUsage)
                            <td class="px-6 py-4 text-xs text-slate-600 dark:text-slate-400 whitespace-nowrap">
                                @if ($usage['allocated'])
                                    {{ rtrim(rtrim(number_format($usage['allocated']['cpu'], 2), '0'), '.') }} CPU
                                    · {{ number_format($usage['allocated']['memory_mb'] / 1024, $usage['allocated']['memory_mb'] >= 1024 ? 0 : 1) }} GB RAM
                                    · {{ number_format($usage['allocated']['disk_gb'], 0) }} GB disk
                                    @if ($usage['allocated']['bandwidth_gb'] !== null)
                                        · {{ number_format($usage['allocated']['bandwidth_gb'], 0) }} GB transfer
                                    @endif
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm tabular-nums whitespace-nowrap">
                                @if (($usage['consumed']['disk_gb'] ?? null) !== null)
                                    {{ number_format($usage['consumed']['disk_gb'], 1) }}
                                    @if ($usage['allocated'])
                                        <span class="text-slate-400">/ {{ number_format($usage['allocated']['disk_gb'], 0) }} GB</span>
                                    @else
                                        <span class="text-slate-400">GB</span>
                                    @endif
                                @else
                                    <span class="text-slate-400">No sample yet</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm tabular-nums whitespace-nowrap">
                                @if ($usage['consumed'])
                                    {{ number_format($usage['consumed']['transfer_gb'], 2) }} GB
                                    @if (($usage['allocated']['bandwidth_gb'] ?? null) !== null)
                                        <span class="text-slate-400">/ {{ number_format($usage['allocated']['bandwidth_gb'], 0) }}</span>
                                    @endif
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        @endif
                        <td class="px-6 py-4 text-right">
                            @include('reseller.services.partials.row-actions', ['service' => $service])
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    {{ $paginator->links() }}
@else
    <div class="p-8 text-center ui-card text-slate-500">{{ $empty }}</div>
@endif
