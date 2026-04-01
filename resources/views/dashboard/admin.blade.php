@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
<div class="space-y-8">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Dashboard</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Welcome back! Here's your business overview.</p>
    </div>

    <!-- Key Metrics -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Total Customers -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Customers</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $totalCustomers }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.856-1.487M15 10h.01M10 10a4 4 0 11-8 0 4 4 0 018 0zM9 20H3v-2a6 6 0 0112 0v2z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-4">Active accounts</p>
        </div>

        <!-- Active Services -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-colors">
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
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-4">Running now</p>
        </div>

        <!-- Unpaid Invoices -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Unpaid Invoices</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">${{ number_format($unpaidInvoices, 2) }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-4">Awaiting payment</p>
        </div>

        <!-- Total Revenue -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Revenue</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">${{ number_format($totalRevenue, 2) }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-violet-100 dark:bg-violet-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-4">All time</p>
        </div>
    </div>

    <!-- Recent Invoices -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="p-6 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Invoices</h2>
                <a href="{{ route('invoices.index') }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
            </div>
        </div>
        <div class="divide-y divide-slate-200 dark:divide-slate-800">
            @forelse ($recentInvoices as $invoice)
                <div class="p-6 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="font-medium text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</p>
                            <p class="text-sm text-slate-600 dark:text-slate-400">{{ $invoice->user->name }}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="font-semibold text-slate-900 dark:text-white">${{ number_format($invoice->total, 2) }}</p>
                        <span class="inline-block px-2 py-1 rounded text-xs font-medium {{ $invoice->status === 'paid' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-200' : 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-200' }}">
                            {{ ucfirst($invoice->status) }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="p-12 text-center">
                    <p class="text-slate-500 dark:text-slate-400">No invoices yet</p>
                </div>
            @endforelse
        </div>
    </div>

    <!-- Open Tickets -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Open Support Tickets</h2>
        <div class="flex items-center justify-center py-8">
            <div class="text-center">
                <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </div>
                <p class="font-medium text-slate-900 dark:text-white">{{ $openTickets }} open tickets</p>
                <p class="text-sm text-slate-500 dark:text-slate-400">Needs attention</p>
            </div>
        </div>
    </div>
</div>
@endsection
