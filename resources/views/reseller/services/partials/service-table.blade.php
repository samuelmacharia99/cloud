@php
    $paginator = $paginator ?? collect();
    $empty = $empty ?? 'No services in this group.';
@endphp

@if ($paginator->count())
    <div class="ui-card overflow-hidden">
        <table class="w-full">
            <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                <tr>
                    <th class="px-6 py-4 text-left text-sm font-semibold">Service</th>
                    <th class="px-6 py-4 text-left text-sm font-semibold">Customer</th>
                    <th class="px-6 py-4 text-left text-sm font-semibold">Status</th>
                    <th class="px-6 py-4 text-right text-sm font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                @foreach ($paginator as $service)
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                        <td class="px-6 py-4">
                            <p class="font-medium text-slate-900 dark:text-white">{{ $service->name }}</p>
                            <p class="text-xs text-slate-500">{{ $service->customerPlanName() }}</p>
                        </td>
                        <td class="px-6 py-4 text-sm">{{ $service->user?->name }}</td>
                        <td class="px-6 py-4"><x-status-badge :status="$service->status" type="service" /></td>
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
