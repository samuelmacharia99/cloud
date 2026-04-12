@extends('layouts.customer')

@section('title', 'Reseller Dashboard')

@section('content')
<div class="space-y-8">
    <!-- Welcome Banner -->
    <div class="bg-gradient-to-r from-purple-600 to-purple-700 dark:from-purple-900 dark:to-purple-800 rounded-xl border border-purple-500 dark:border-purple-700 p-8 text-white">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-bold">Welcome back, {{ auth()->user()->name }}</h1>
                <p class="text-purple-100 mt-2">Manage your services, customers, and commissions.</p>
            </div>
            <div class="flex items-center gap-2 px-4 py-2 rounded-full bg-emerald-100 dark:bg-emerald-950">
                <div class="w-2 h-2 rounded-full bg-emerald-600"></div>
                <span class="text-sm font-medium text-emerald-700 dark:text-emerald-300">Reseller Active</span>
            </div>
        </div>
    </div>

    <!-- Key Metrics -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Active Services -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Active Services</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $activeServices }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-emerald-100 dark:bg-emerald-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Running now</p>
        </div>

        <!-- Customers Served -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Customers Served</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $managedCustomers->count() }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.856-1.487M15 10h.01M10 10a4 4 0 11-8 0 4 4 0 018 0zM9 20H3v-2a6 6 0 0112 0v2z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Your customer base</p>
        </div>

        <!-- Total Revenue -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Revenue</p>
                    <p class="text-3xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">KSH {{ number_format($totalRevenue, 2) }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-emerald-100 dark:bg-emerald-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">From managed services</p>
        </div>

        <!-- Estimated Commission -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Est. Commission</p>
                    <p class="text-3xl font-bold text-purple-600 dark:text-purple-400 mt-2">KSH {{ number_format($totalCommission, 2) }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">20% of revenue</p>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Managed Services (left column spans 2) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Recent Managed Services -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Managed Services</h2>
                    <span class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $totalServices }} total</span>
                </div>
                @if ($managedServices->count() > 0)
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($managedServices->take(6) as $service)
                            <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <p class="font-medium text-slate-900 dark:text-white">{{ $service->product?->name ?? 'Service' }}</p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Customer: {{ $service->user?->name ?? 'N/A' }}</p>
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
                        <p class="text-slate-500 dark:text-slate-400">No services managed yet</p>
                    </div>
                @endif
            </div>

            <!-- Recent Invoices -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Invoices</h2>
                </div>
                @if ($recentInvoices->count() > 0)
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($recentInvoices->take(5) as $invoice)
                            <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">{{ $invoice->user?->name ?? 'N/A' }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-slate-900 dark:text-white">KSH {{ number_format($invoice->total, 2) }}</p>
                                    <x-status-badge :status="$invoice->status" type="invoice" />
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

        <!-- Right Sidebar: Summary Cards -->
        <div class="space-y-6">
            <!-- Your Plan Card -->
            <div class="bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-950 dark:to-purple-900 rounded-xl border border-purple-200 dark:border-purple-800 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Your Plan</h3>
                    <a href="{{ route('reseller.packages.index') }}" class="text-xs text-purple-600 dark:text-purple-400 hover:underline font-medium">Manage</a>
                </div>
                @if ($resellerPackage)
                    <p class="text-lg font-bold text-purple-700 dark:text-purple-300">{{ $resellerPackage->name }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">{{ ucfirst($resellerPackage->billing_cycle) }}</p>
                    <!-- Service Slots Usage -->
                    <div class="mb-4">
                        <div class="flex justify-between text-xs text-slate-600 dark:text-slate-400 mb-1">
                            <span>Service Slots</span>
                            <span>{{ $activeServices }} / {{ $resellerPackage->storage_space }}</span>
                        </div>
                        @php
                            $servicePct = $resellerPackage->storage_space > 0
                                ? min(100, round(($activeServices / $resellerPackage->storage_space) * 100))
                                : 0;
                            $serviceColor = $servicePct >= 90 ? 'bg-red-500' : ($servicePct >= 75 ? 'bg-amber-500' : 'bg-emerald-500');
                        @endphp
                        <div class="w-full h-2 bg-slate-300 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div class="{{ $serviceColor }} h-2 rounded-full transition-all" style="width: {{ $servicePct }}%"></div>
                        </div>
                    </div>
                    <!-- Customers Usage -->
                    <div>
                        <div class="flex justify-between text-xs text-slate-600 dark:text-slate-400 mb-1">
                            <span>Customers</span>
                            <span>{{ $managedCustomers->count() }} / {{ $resellerPackage->max_users }}</span>
                        </div>
                        @php
                            $customerPct = $resellerPackage->max_users > 0
                                ? min(100, round(($managedCustomers->count() / $resellerPackage->max_users) * 100))
                                : 0;
                            $customerColor = $customerPct >= 90 ? 'bg-red-500' : ($customerPct >= 75 ? 'bg-amber-500' : 'bg-emerald-500');
                        @endphp
                        <div class="w-full h-2 bg-slate-300 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div class="{{ $customerColor }} h-2 rounded-full transition-all" style="width: {{ $customerPct }}%"></div>
                        </div>
                    </div>
                @else
                    <p class="text-sm text-amber-700 dark:text-amber-400 font-medium mb-4">No active plan</p>
                    <a href="{{ route('reseller.packages.index') }}" class="w-full block text-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition-colors">
                        Choose a Package
                    </a>
                @endif
            </div>

            <!-- Managed Customers List -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Your Customers</h3>
                </div>
                @if ($managedCustomers->count() > 0)
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($managedCustomers->take(5) as $customer)
                            <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <p class="font-medium text-sm text-slate-900 dark:text-white">{{ $customer->name }}</p>
                                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">{{ $customer->email }}</p>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-6 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No customers served yet</p>
                    </div>
                @endif
            </div>

            <!-- Performance Summary -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="font-semibold text-slate-900 dark:text-white mb-4">Service Status</h3>
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Active</span>
                        <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $activeServices }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Suspended</span>
                        <span class="font-semibold text-amber-600 dark:text-amber-400">{{ $suspendedServices }}</span>
                    </div>
                    <div class="flex items-center justify-between pt-3 border-t border-slate-200 dark:border-slate-700">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Total Managed</span>
                        <span class="font-semibold text-slate-900 dark:text-white">{{ $totalServices }}</span>
                    </div>
                </div>
            </div>

            <!-- Outstanding Balance Card -->
            <div class="bg-gradient-to-br from-amber-50 to-orange-50 dark:from-amber-950 dark:to-orange-950 rounded-xl border border-amber-200 dark:border-amber-800 p-6">
                <h3 class="font-semibold text-slate-900 dark:text-white mb-2">Pending Payments</h3>
                <p class="text-3xl font-bold text-amber-600 dark:text-amber-400">
                    KSH {{ number_format($outstandingBalance, 2) }}
                </p>
                <p class="text-sm text-amber-700 dark:text-amber-300 mt-2">
                    From unpaid customer invoices
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
