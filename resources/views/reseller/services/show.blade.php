@extends('layouts.reseller')

@section('title', $service->name)

@section('content')
<div class="space-y-6 max-w-4xl">
    <div>
        <a href="{{ route('reseller.services.index') }}" class="text-sm text-purple-600 hover:text-purple-700">← Back to services</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $service->name }}</h1>
        <p class="text-slate-600 dark:text-slate-400">{{ $service->product?->name }} · <x-status-badge :status="$service->status" type="service" /></p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-3">
            <h2 class="font-semibold text-slate-900 dark:text-white">Customer</h2>
            <p class="text-sm">{{ $service->user?->name }}</p>
            <p class="text-sm text-slate-500">{{ $service->user?->email }}</p>
            <a href="{{ route('reseller.customers.show', $service->user) }}" class="inline-block text-sm text-purple-600">View customer profile</a>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-3">
            <h2 class="font-semibold text-slate-900 dark:text-white">Billing</h2>
            <p class="text-sm">Cycle: {{ ucfirst($service->billing_cycle ?? 'n/a') }}</p>
            <p class="text-sm">Next due: {{ $service->next_due_date?->format('M d, Y') ?? 'N/A' }}</p>
            @if ($service->custom_price)
                <p class="text-sm">Price: KES {{ number_format($service->custom_price, 2) }}</p>
            @endif
        </div>
    </div>
</div>
@endsection
