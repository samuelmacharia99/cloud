@extends('layouts.reseller')

@section('title', $customer->name)

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $customer->name }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">{{ $customer->email }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('reseller.customers.edit', $customer) }}" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                Edit
            </a>
            <a href="{{ route('reseller.customers.index') }}" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-medium rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition">
                Back
            </a>
        </div>
    </div>

    <!-- Key Info Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <!-- Services -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Active Services</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $customer->services->where('status', 'active')->count() }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Invoices -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Invoices</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $customer->invoices->count() }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Status -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Account Status</p>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium mt-2 {{ $customer->status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($customer->status === 'suspended' ? 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400') }}">
                        {{ ucfirst($customer->status) }}
                    </span>
                </div>
                <div class="w-12 h-12 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                    <svg class="w-6 h-6 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Domains -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Domains</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $customer->domains->count() }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                    <svg class="w-6 h-6 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer Details -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Contact Info -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Contact Information</h2>
            <div class="space-y-3 text-sm">
                <div>
                    <p class="text-slate-600 dark:text-slate-400 text-xs uppercase font-medium">Email</p>
                    <p class="text-slate-900 dark:text-white">{{ $customer->email }}</p>
                </div>
                <div>
                    <p class="text-slate-600 dark:text-slate-400 text-xs uppercase font-medium">Phone</p>
                    <p class="text-slate-900 dark:text-white">{{ $customer->phone ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-slate-600 dark:text-slate-400 text-xs uppercase font-medium">Company</p>
                    <p class="text-slate-900 dark:text-white">{{ $customer->company ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-slate-600 dark:text-slate-400 text-xs uppercase font-medium">Country</p>
                    <p class="text-slate-900 dark:text-white">{{ $customer->country ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-slate-600 dark:text-slate-400 text-xs uppercase font-medium">City</p>
                    <p class="text-slate-900 dark:text-white">{{ $customer->city ?: '-' }}</p>
                </div>
            </div>
        </div>

        <!-- Services (left column spans 2) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Services List -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Services</h2>
                    <span class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $customer->services->count() }} total</span>
                </div>
                @if ($customer->services->count() > 0)
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($customer->services as $service)
                            <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <p class="font-medium text-slate-900 dark:text-white">{{ $service->name }}</p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">{{ $service->product?->name ?? 'Product' }} • {{ ucfirst($service->billing_cycle) }}</p>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $service->status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($service->status === 'suspended' ? 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400') }}">
                                            {{ ucfirst($service->status) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-12 text-center">
                        <p class="text-slate-500 dark:text-slate-400">No services yet</p>
                    </div>
                @endif
            </div>

            <!-- Recent Invoices -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Invoices</h2>
                </div>
                @if ($customer->invoices->count() > 0)
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($customer->invoices->take(5) as $invoice)
                            <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">{{ $invoice->created_at->format('M d, Y') }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-slate-900 dark:text-white">KSH {{ number_format($invoice->total, 2) }}</p>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium mt-1 {{ $invoice->status === 'paid' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($invoice->status === 'unpaid' ? 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400') }}">
                                        {{ ucfirst($invoice->status) }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-12 text-center">
                        <p class="text-slate-500 dark:text-slate-400">No invoices yet</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Notes Section -->
    @if ($customer->notes)
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Notes</h2>
        <p class="text-slate-600 dark:text-slate-400 text-sm leading-relaxed">{{ $customer->notes }}</p>
    </div>
    @endif
</div>
@endsection
