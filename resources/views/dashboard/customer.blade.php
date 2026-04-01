@extends('layouts.app')

@section('title', 'My Dashboard')

@section('content')
<div class="space-y-8">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Welcome, {{ auth()->user()->name }}</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your services, invoices, and domains in one place.</p>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Active Services -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Active Services</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $activeServices->count() }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
            <a href="{{ route('services.index') }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 mt-4 block">View services →</a>
        </div>

        <!-- Outstanding Balance -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Outstanding Balance</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">${{ number_format($outstandingBalance, 2) }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl {{ $outstandingBalance > 0 ? 'bg-amber-100 dark:bg-amber-950' : 'bg-emerald-100 dark:bg-emerald-950' }} flex items-center justify-center">
                    <svg class="w-6 h-6 {{ $outstandingBalance > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <a href="{{ route('invoices.index') }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 mt-4 block">Pay now →</a>
        </div>

        <!-- Domains -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Active Domains</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $domains->count() }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-violet-100 dark:bg-violet-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-4">Registered</p>
        </div>
    </div>

    <!-- Active Services -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="p-6 border-b border-slate-200 dark:border-slate-800">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Your Active Services</h2>
        </div>
        @if ($activeServices->count() > 0)
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                @foreach ($activeServices as $service)
                    <div class="p-6 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-100 to-blue-200 dark:from-blue-900 dark:to-blue-800 flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-slate-900 dark:text-white">{{ $service->name }}</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">{{ $service->product->name }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Renews</p>
                            <p class="font-semibold text-slate-900 dark:text-white">{{ $service->next_due_date->format('M d, Y') }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="p-12 text-center">
                <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <p class="font-medium text-slate-900 dark:text-white">No active services</p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Get started by purchasing a service</p>
                <a href="{{ route('products.index') }}" class="inline-block mt-4 px-4 py-2 rounded-lg bg-blue-600 dark:bg-blue-500 text-white text-sm font-medium hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">Browse products</a>
            </div>
        @endif
    </div>

    <!-- Upcoming Due Invoices -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="p-6 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Upcoming Due Invoices</h2>
                <a href="{{ route('invoices.index') }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
            </div>
        </div>
        @if ($upcomingDueInvoices->count() > 0)
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                @foreach ($upcomingDueInvoices as $invoice)
                    <div class="p-6 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-950 flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Due {{ $invoice->due_date->format('M d, Y') }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold text-slate-900 dark:text-white">${{ number_format($invoice->total, 2) }}</p>
                            <a href="{{ route('invoices.show', $invoice) }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">Pay now →</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="p-12 text-center">
                <p class="text-slate-500 dark:text-slate-400">No upcoming invoices</p>
            </div>
        @endif
    </div>

    <!-- Open Tickets -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="p-6 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Support Tickets</h2>
                <a href="{{ route('tickets.create') }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">Create new →</a>
            </div>
        </div>
        @if ($openTickets->count() > 0)
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                @foreach ($openTickets as $ticket)
                    <div class="p-6 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors flex items-center justify-between">
                        <div class="flex items-center gap-4 flex-1">
                            <div class="px-3 py-1 rounded-full text-xs font-medium {{ $ticket->priority === 'high' || $ticket->priority === 'urgent' ? 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-200' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' }}">
                                {{ ucfirst($ticket->priority) }}
                            </div>
                            <div>
                                <p class="font-semibold text-slate-900 dark:text-white">{{ $ticket->title }}</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">{{ $ticket->status === 'open' ? 'Waiting for response' : ucfirst($ticket->status) }}</p>
                            </div>
                        </div>
                        <a href="{{ route('tickets.show', $ticket) }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View →</a>
                    </div>
                @endforeach
            </div>
        @else
            <div class="p-12 text-center">
                <p class="text-slate-500 dark:text-slate-400">No open tickets</p>
            </div>
        @endif
    </div>
</div>
@endsection
