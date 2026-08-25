@extends('layouts.admin')

@section('title', 'Dashboard')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Dashboard</p>
@endsection

@section('content')
<div class="space-y-8">
    <!-- Header -->
    <x-admin-page-header title="Dashboard" description="Welcome back! Here's what needs you today." />

    <x-admin-action-queue :attention="$adminAttention ?? []" />

    <!-- Primary Metrics -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Total Customers -->
        <a href="{{ route('admin.customers.index') }}" class="block ui-card ui-card-interactive p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Customers</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $totalCustomers }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.856-1.487M15 10h.01M10 10a4 4 0 11-8 0 4 4 0 018 0zM9 20H3v-2a6 6 0 0112 0v2z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">{{ $platformCustomers }} platform · {{ $resellerManagedCustomers }} reseller-managed · {{ $totalResellers }} resellers</p>
        </a>

        <!-- Active Services -->
        <a href="{{ route('admin.services.index') }}" class="block ui-card ui-card-interactive p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Active Services</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $activeServices }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-100 dark:bg-emerald-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Running now · view services</p>
        </a>

        <!-- Unpaid Invoices -->
        <a href="{{ route('admin.invoices.index', ['status' => 'unpaid']) }}" class="block ui-card ui-card-interactive p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Unpaid Invoices</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">KES {{ number_format($unpaidInvoiceTotal, 2) }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Platform AR remaining · view invoices</p>
        </a>

        <!-- Total Revenue -->
        <a href="{{ route('admin.payments.index') }}" class="block ui-card ui-card-interactive p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Platform Revenue</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">KES {{ number_format($totalRevenue, 2) }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-violet-100 dark:bg-violet-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Direct customers &amp; resellers · base KES</p>
        </a>
    </div>

    <!-- Secondary Metrics -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Revenue This Month -->
        <a href="{{ route('admin.payments.index', ['status' => 'completed', 'from_date' => $collectedThisMonthStart ?? now()->startOfMonth()->toDateString(), 'to_date' => $collectedThisMonthEnd ?? now()->endOfMonth()->toDateString()]) }}" class="block ui-card ui-card-interactive p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Revenue This Month</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">KES {{ number_format($collectedThisMonth, 2) }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-100 dark:bg-emerald-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Platform revenue collected · {{ $collectedThisMonthLabel ?? now()->format('F Y') }}</p>
        </a>

        <!-- Overdue Invoices -->
        <a href="{{ route('admin.invoices.index', ['status' => 'overdue']) }}" class="block ui-card ui-card-interactive p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Overdue Invoices</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">KES {{ number_format($overdueInvoiceTotal, 2) }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-red-100 dark:bg-red-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4v.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Platform AR remaining · view overdue</p>
        </a>

        <!-- Collected Today -->
        <a href="{{ route('admin.payments.index', ['status' => 'completed', 'from_date' => $collectedTodayDate ?? now()->toDateString(), 'to_date' => $collectedTodayDate ?? now()->toDateString()]) }}" class="block ui-card ui-card-interactive p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Collected Today</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">KES {{ number_format($collectedToday, 2) }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Platform revenue · {{ \Illuminate\Support\Carbon::parse($collectedTodayDate ?? now())->format('M j, Y') }}</p>
        </a>

        <!-- Urgent Tickets -->
        <a href="{{ route('tickets.index') }}" class="block ui-card ui-card-interactive p-6 {{ $openTickets > 0 ? 'ring-1 ring-red-200/60 dark:ring-red-900/40' : '' }}">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400 flex items-center gap-2">
                        Open Tickets
                        @if($openTickets > 0)
                            <x-admin-attention-dot :count="$openTickets" />
                        @endif
                    </p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $openTickets }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-red-100 dark:bg-red-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">{{ $urgentTickets }} urgent · click to view</p>
        </a>
    </div>

    <!-- Analytics Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Revenue Trend Chart -->
        <div class="ui-card overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Platform Revenue Trend</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Last 30 days · base KES · excludes reseller customer retail</p>
            </div>
            <div class="p-6">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <!-- Signup Trend Chart -->
        <div class="ui-card overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">New Platform Signups</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Last 7 days · excludes reseller-managed customers</p>
            </div>
            <div class="p-6">
                <canvas id="signupChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Status Breakdowns -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Service Status -->
        <div class="ui-card p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Service Status Breakdown</h2>
            <div class="space-y-4">
                @php
                    $statusColors = [
                        'active' => ['bg' => 'bg-emerald-500'],
                        'pending' => ['bg' => 'bg-blue-500'],
                        'provisioning' => ['bg' => 'bg-cyan-500'],
                        'suspended' => ['bg' => 'bg-amber-500'],
                        'failed' => ['bg' => 'bg-rose-500'],
                        'terminated' => ['bg' => 'bg-red-500'],
                        'cancelled' => ['bg' => 'bg-slate-500'],
                    ];
                    $total = array_sum($serviceStatus);
                @endphp
                @foreach($serviceStatus as $status => $count)
                    @continue($count < 1 && ! in_array($status, ['active', 'suspended', 'failed'], true))
                    @php $percentage = $total > 0 ? round(($count / $total) * 100) : 0; @endphp
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ ucfirst($status) }}</span>
                            <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $count }} ({{ $percentage }}%)</span>
                        </div>
                        <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-2">
                            <div class="h-2 rounded-full {{ $statusColors[$status]['bg'] ?? 'bg-slate-400' }}" style="width: {{ $percentage }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Invoice Status -->
        <div class="ui-card p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Platform Invoice Status</h2>
            <div class="space-y-4">
                @php
                    $invoiceStatusColors = [
                        'unpaid' => ['bg' => 'bg-amber-500', 'text' => 'text-amber-700 dark:text-amber-400'],
                        'paid' => ['bg' => 'bg-emerald-500', 'text' => 'text-emerald-700 dark:text-emerald-400'],
                        'overdue' => ['bg' => 'bg-red-500', 'text' => 'text-red-700 dark:text-red-400'],
                        'cancelled' => ['bg' => 'bg-slate-500', 'text' => 'text-slate-700 dark:text-slate-400'],
                    ];
                    $invoiceTotal = array_sum($invoiceStatus);
                @endphp
                @foreach($invoiceStatus as $status => $count)
                    @php $percentage = $invoiceTotal > 0 ? round(($count / $invoiceTotal) * 100) : 0; @endphp
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ ucfirst($status) }}</span>
                            <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $count }} ({{ $percentage }}%)</span>
                        </div>
                        <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-2">
                            <div class="h-2 rounded-full {{ $invoiceStatusColors[$status]['bg'] }}" style="width: {{ $percentage }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Activity Feeds -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Recent Customers -->
        <div class="ui-card overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Customers</h2>
                    <a href="{{ route('admin.customers.index') }}" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                @forelse ($recentCustomers as $customer)
                    <a href="{{ route('admin.customers.show', $customer) }}" class="block p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center flex-shrink-0 text-white font-semibold">
                                {{ strtoupper(substr($customer->name, 0, 1)) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ $customer->name }}</p>
                                <p class="text-xs text-slate-600 dark:text-slate-400">{{ $customer->email }}</p>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No customers yet</p>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Recent Services -->
        <div class="ui-card overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Services</h2>
                    <a href="{{ route('admin.services.index') }}" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                @forelse ($recentServices as $service)
                    <a href="{{ route('admin.services.show', $service) }}" class="block p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ $service->product?->name ?? 'Unknown' }}</p>
                                <p class="text-xs text-slate-600 dark:text-slate-400">{{ $service->user?->name ?? 'Unknown' }}</p>
                            </div>
                            <x-status-badge :status="$service->status" type="service" />
                        </div>
                    </a>
                @empty
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No services yet</p>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Recent Payments -->
        <div class="ui-card overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Payments</h2>
                    <a href="{{ route('admin.payments.index') }}" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                @forelse ($recentPayments as $payment)
                    <a href="{{ route('admin.payments.show', $payment) }}" class="block p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $payment->user?->name ?? 'Unknown' }}</p>
                                <p class="text-xs text-slate-600 dark:text-slate-400">{{ $payment->payment_method?->label() ?? 'Manual' }}</p>
                            </div>
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">KES {{ number_format($payment->amount, 2) }}</p>
                        </div>
                    </a>
                @empty
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No payments yet</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- More Activity Feeds -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Recent Invoices -->
        <div class="ui-card overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Invoices</h2>
                    <a href="{{ route('admin.invoices.index') }}" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                @forelse ($recentInvoices as $invoice)
                    <a href="{{ route('admin.invoices.show', $invoice) }}" class="block p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</p>
                                <p class="text-xs text-slate-600 dark:text-slate-400">{{ $invoice->user?->name ?? 'Unknown' }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">KES {{ number_format($invoice->total_base_kes ?? $invoice->total, 2) }}</p>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No invoices yet</p>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Open Tickets -->
        <div class="ui-card overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Open Tickets</h2>
                    <a href="{{ route('tickets.index') }}" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                @forelse ($openTickets_data as $ticket)
                    <a href="{{ route('tickets.show', $ticket) }}" class="block p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ $ticket->title ?? 'No subject' }}</p>
                                <p class="text-xs text-slate-600 dark:text-slate-400">{{ $ticket->user?->name ?? 'Unknown' }}</p>
                            </div>
                            <x-status-badge :status="$ticket->priority" type="priority" />
                        </div>
                    </a>
                @empty
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No open tickets</p>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Top Products -->
        <div class="ui-card overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Top Products</h2>
                    <a href="{{ route('admin.products.index') }}" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                @forelse ($topProducts as $product)
                    <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ $product->name }}</p>
                                <p class="text-xs text-slate-600 dark:text-slate-400">KES {{ number_format((float) ($product->monthly_price ?? $product->yearly_price ?? 0), 2) }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $product->services_count }}</p>
                                <p class="text-xs text-slate-600 dark:text-slate-400">active</p>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No products yet</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Revenue Chart
    const revenueCtx = document.getElementById('revenueChart');
    if (revenueCtx) {
        const revenueData = {!! $revenueData !!};
        const revenueLabels = {!! $revenueLabels !!};

        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: revenueLabels,
                datasets: [{
                    label: 'Platform revenue (KES)',
                    data: revenueData,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#8b5cf6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        labels: {
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#cbd5e1' : '#475569',
                            font: { size: 12, weight: '500' }
                        }
                    },
                    filler: {
                        propagate: true
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'KES ' + value.toLocaleString();
                            },
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#cbd5e1' : '#64748b',
                            font: { size: 11 }
                        },
                        grid: {
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#334155' : '#e2e8f0',
                            drawBorder: false
                        }
                    },
                    x: {
                        ticks: {
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#cbd5e1' : '#64748b',
                            font: { size: 11 }
                        },
                        grid: {
                            display: false,
                            drawBorder: false
                        }
                    }
                }
            }
        });
    }

    // Signup Chart
    const signupCtx = document.getElementById('signupChart');
    if (signupCtx) {
        const signupData = {!! $signupData !!};
        const signupLabels = {!! $signupLabels !!};

        new Chart(signupCtx, {
            type: 'bar',
            data: {
                labels: signupLabels,
                datasets: [{
                    label: 'Platform signups',
                    data: signupData,
                    backgroundColor: '#3b82f6',
                    borderRadius: 6,
                    borderSkipped: false,
                    hoverBackgroundColor: '#1e40af'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                indexAxis: undefined,
                plugins: {
                    legend: {
                        display: true,
                        labels: {
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#cbd5e1' : '#475569',
                            font: { size: 12, weight: '500' }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#cbd5e1' : '#64748b',
                            font: { size: 11 }
                        },
                        grid: {
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#334155' : '#e2e8f0',
                            drawBorder: false
                        }
                    },
                    x: {
                        ticks: {
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#cbd5e1' : '#64748b',
                            font: { size: 11 }
                        },
                        grid: {
                            display: false,
                            drawBorder: false
                        }
                    }
                }
            }
        });
    }
</script>
@endpush
