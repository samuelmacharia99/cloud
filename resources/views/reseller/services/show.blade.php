@extends('layouts.reseller')

@section('title', $service->name)

@section('content')
<div class="space-y-6 max-w-4xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('reseller.services.index') }}" class="text-sm text-purple-600">← Services</a>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $service->name }}</h1>
            <p class="text-slate-600 dark:text-slate-400">{{ $service->product?->name }} · <x-status-badge :status="$service->status" type="service" /></p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canSuspend ?? false)
                <form method="POST" action="{{ route('reseller.services.suspend', $service) }}" onsubmit="return confirm('Suspend this service?');">@csrf<button type="submit" class="px-4 py-2 bg-amber-600 text-white rounded-lg text-sm">Suspend</button></form>
            @endif
            @if ($canUnsuspend ?? false)
                <form method="POST" action="{{ route('reseller.services.unsuspend', $service) }}">@csrf<button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm">Unsuspend</button></form>
            @endif
            @if ($canTerminate ?? false)
                <form method="POST" action="{{ route('reseller.services.terminate', $service) }}" onsubmit="return confirm('Terminate permanently?');">@csrf<button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm">Terminate</button></form>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-3">
            <h2 class="font-semibold">Customer</h2>
            <p class="text-sm">{{ $service->user?->name }}</p>
            <p class="text-sm text-slate-500">{{ $service->user?->email }}</p>
            <a href="{{ route('reseller.customers.show', $service->user) }}" class="text-sm text-purple-600">Customer profile →</a>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-3">
            <h2 class="font-semibold">Billing</h2>
            <p class="text-sm">Cycle: {{ ucfirst($service->billing_cycle ?? 'n/a') }}</p>
            <p class="text-sm">Next due: {{ $service->next_due_date?->format('M d, Y') ?? 'N/A' }}</p>
            @if ($service->custom_price)
                <p class="text-sm">Retail price: KES {{ number_format($service->custom_price, 2) }}</p>
            @endif
            @if ($service->invoice)
                <a href="{{ route('reseller.customer-invoices.show', $service->invoice) }}" class="text-sm text-purple-600">Invoice →</a>
            @endif
        </div>
    </div>

    @if (!empty($managementLinks['username']) || !empty($managementLinks['panel_url']))
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="font-semibold mb-3">Technical details</h2>
            <dl class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                @if ($managementLinks['driver'])
                    <div><dt class="text-slate-500">Driver</dt><dd>{{ $managementLinks['driver'] }}</dd></div>
                @endif
                @if ($managementLinks['username'])
                    <div><dt class="text-slate-500">Username</dt><dd>{{ $managementLinks['username'] }}</dd></div>
                @endif
                @if ($managementLinks['domain'])
                    <div><dt class="text-slate-500">Primary domain</dt><dd>{{ $managementLinks['domain'] }}</dd></div>
                @endif
                @if ($managementLinks['ip_address'])
                    <div><dt class="text-slate-500">IP</dt><dd>{{ $managementLinks['ip_address'] }}</dd></div>
                @endif
            </dl>
            @if ($managementLinks['panel_url'])
                <a href="{{ $managementLinks['panel_url'] }}" target="_blank" rel="noopener" class="inline-block mt-4 text-sm text-purple-600">Open hosting panel →</a>
            @endif
            @if ($managementLinks['container_deployment'])
                <p class="text-xs text-slate-500 mt-2">Container deployment #{{ $managementLinks['container_deployment'] }} — customer manages via their portal.</p>
            @endif
        </div>
    @endif
</div>
@endsection
