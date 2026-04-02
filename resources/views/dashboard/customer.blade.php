@extends('layouts.customer')

@section('title', 'My Dashboard')

@section('content')
<div class="space-y-8">
    <!-- Welcome Banner -->
    <div class="bg-gradient-to-r from-blue-50 dark:from-blue-950 to-blue-100 dark:to-blue-900 rounded-2xl border border-blue-200 dark:border-blue-800 p-8">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Welcome back, {{ auth()->user()->name }}</h1>
                <p class="text-slate-600 dark:text-slate-400 mt-2">Manage your services, invoices, and payments in one place.</p>
            </div>
            <div class="hidden lg:flex items-center gap-2 px-4 py-2 rounded-full bg-emerald-100 dark:bg-emerald-950">
                <div class="w-2 h-2 rounded-full bg-emerald-600"></div>
                <span class="text-sm font-medium text-emerald-700 dark:text-emerald-300">Account Active</span>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Active Services -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Active Services</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $activeServices->count() }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Running now</p>
        </div>

        <!-- Unpaid Invoices Count -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Unpaid Invoices</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $upcomingDueInvoices->count() }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Awaiting payment</p>
        </div>

        <!-- Outstanding Balance -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Outstanding Balance</p>
            <p class="text-3xl font-bold {{ $outstandingBalance > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }} mt-2">${{ number_format($outstandingBalance, 2) }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">{{ $outstandingBalance > 0 ? 'Due soon' : 'All paid' }}</p>
        </div>

        <!-- Open Tickets -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Open Support Tickets</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $openTickets->count() }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Need help</p>
        </div>
    </div>

    <!-- Deploy New Service CTA Banner -->
    <div class="bg-gradient-to-r from-emerald-600 to-teal-600 rounded-2xl p-8 text-white">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold">Ready to expand?</h2>
                <p class="text-emerald-100 mt-1">Deploy a new service instantly</p>
            </div>
            <button class="px-6 py-3 bg-white hover:bg-emerald-50 text-emerald-600 font-semibold rounded-lg transition">
                <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Deploy New Service
            </button>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Recent Invoices & Payments (left column spans 2) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Recent Invoices -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Invoices</h2>
                    <a href="{{ route('customer.invoices.index') }}" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
                @if ($upcomingDueInvoices->count() > 0)
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($upcomingDueInvoices->take(5) as $invoice)
                            <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Due: {{ $invoice->due_date->format('M d, Y') }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-slate-900 dark:text-white">${{ number_format($invoice->total, 2) }}</p>
                                    <x-status-badge :status="$invoice->status" type="invoice" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-12 text-center">
                        <p class="text-slate-500 dark:text-slate-400">All invoices paid!</p>
                    </div>
                @endif
            </div>

            <!-- Recent Payments -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Payments</h2>
                    <a href="{{ route('customer.payments.index') }}" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
                <div class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($activeServices->take(3) as $service)
                        <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $service->product?->name ?? 'Service' }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">Renewal: {{ $service->next_due_date?->format('M d, Y') ?? 'N/A' }}</p>
                                </div>
                                <p class="font-semibold text-slate-900 dark:text-white">${{ number_format($service->product?->price ?? 0, 2) }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="p-12 text-center">
                            <p class="text-slate-500 dark:text-slate-400">No services yet</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Right Sidebar: Services & Support -->
        <div class="space-y-6">
            <!-- Active Services Quick List -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                    <h3 class="font-semibold text-slate-900 dark:text-white">My Services</h3>
                </div>
                @if ($activeServices->count() > 0)
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($activeServices->take(5) as $service)
                            <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <p class="font-medium text-sm text-slate-900 dark:text-white">{{ $service->product?->name ?? 'Service' }}</p>
                                <div class="flex items-center gap-2 mt-2">
                                    <x-status-badge :status="$service->status" type="service" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-6 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No active services</p>
                    </div>
                @endif
            </div>

            <!-- Account Status Card -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="font-semibold text-slate-900 dark:text-white mb-4">Account Status</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-slate-600 dark:text-slate-400">Email</p>
                        <p class="font-medium text-slate-900 dark:text-white text-xs mt-1">{{ auth()->user()->email }}</p>
                    </div>
                    <div>
                        <p class="text-slate-600 dark:text-slate-400">Status</p>
                        <div class="flex items-center gap-2 mt-1">
                            <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                            <span class="font-medium text-emerald-600 dark:text-emerald-400">Active</span>
                        </div>
                    </div>
                    <div>
                        <p class="text-slate-600 dark:text-slate-400">Member Since</p>
                        <p class="font-medium text-slate-900 dark:text-white text-xs mt-1">{{ auth()->user()->created_at->format('M d, Y') }}</p>
                    </div>
                </div>
            </div>

            <!-- Support Card -->
            <div class="bg-gradient-to-br from-blue-50 dark:from-blue-950 to-blue-100 dark:to-blue-900 rounded-2xl border border-blue-200 dark:border-blue-800 p-6">
                <h3 class="font-semibold text-slate-900 dark:text-white mb-2">Need Help?</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Get support from our team</p>
                <a href="{{ route('customer.tickets.index') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    Open Ticket
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
