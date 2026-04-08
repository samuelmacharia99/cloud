@extends('layouts.customer')

@section('title', 'My Services')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Services</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your active services and subscriptions.</p>
        </div>
        <a href="{{ route('customer.select-techstack') }}" class="inline-flex px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Deploy New Service
        </a>
    </div>

    <!-- Services Cards/Grid -->
    @if ($services->count() > 0)
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($services as $service)
                <a href="{{ route('customer.services.show', $service) }}" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-blue-300 dark:hover:border-blue-700 transition-colors">
                    <!-- Header -->
                    <div class="mb-4">
                        <div class="flex items-start justify-between mb-2">
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $service->product->name }}</h3>
                        </div>
                        <p class="text-xs text-slate-600 dark:text-slate-400">{{ ucfirst(str_replace('_', ' ', $service->product->type)) }}</p>
                    </div>

                    <!-- Status Badge -->
                    <div class="mb-4">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                            @if($service->status === 'active')
                                bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                            @elseif($service->status === 'pending')
                                bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                            @elseif($service->status === 'provisioning')
                                bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                            @elseif($service->status === 'suspended')
                                bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300
                            @elseif($service->status === 'terminated')
                                bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                            @elseif($service->status === 'failed')
                                bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                            @endif
                        ">
                            {{ ucfirst($service->status) }}
                        </span>
                    </div>

                    <!-- Service Details -->
                    <div class="space-y-3 mb-6 pb-6 border-b border-slate-200 dark:border-slate-800">
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-slate-600 dark:text-slate-400">Service ID</span>
                            <span class="text-sm font-medium text-slate-900 dark:text-white">#{{ $service->id }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-slate-600 dark:text-slate-400">Billing Cycle</span>
                            <span class="text-sm font-medium text-slate-900 dark:text-white">{{ ucfirst($service->billing_cycle) }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-slate-600 dark:text-slate-400">Next Due</span>
                            <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $service->next_due_date?->format('M d, Y') ?? '-' }}</span>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex gap-2">
                        <button class="flex-1 px-3 py-2 text-xs font-medium text-slate-700 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white bg-slate-100 dark:bg-slate-800 rounded-lg transition">
                            Restart
                        </button>
                        <button class="flex-1 px-3 py-2 text-xs font-medium text-slate-700 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white bg-slate-100 dark:bg-slate-800 rounded-lg transition">
                            Renew
                        </button>
                    </div>
                </a>
            @endforeach
        </div>
    @else
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-12 text-center">
            <svg class="w-16 h-16 text-slate-300 dark:text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
            </svg>
            <p class="text-slate-600 dark:text-slate-400">You don't have any services yet</p>
            <a href="{{ route('customer.select-techstack') }}" class="inline-block mt-4 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                Deploy Your First Service
            </a>
        </div>
    @endif
</div>
@endsection
