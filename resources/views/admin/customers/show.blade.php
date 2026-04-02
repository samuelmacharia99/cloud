@extends('layouts.admin')

@section('title', $customer->name)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.customers.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Customers</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">{{ $customer->name }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6" x-data="{ tab: 'overview' }">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div class="flex items-start gap-4">
                <!-- Avatar -->
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-xl font-semibold flex-shrink-0">
                    {{ strtoupper(substr($customer->name, 0, 1)) }}
                </div>

                <!-- Header info -->
                <div>
                    <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $customer->name }}</h1>
                    <p class="text-slate-600 dark:text-slate-400 mt-1">{{ $customer->email }}</p>

                    <!-- Status badges -->
                    <div class="flex items-center gap-3 mt-3">
                        <!-- Account status -->
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $customer->status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($customer->status === 'suspended' ? 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400') }}">
                            {{ ucfirst($customer->status) }}
                        </span>

                        <!-- Account type -->
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300">
                            {{ !empty($customer->company) ? 'Company' : 'Individual' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.customers.edit', $customer) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                    Edit Customer
                </a>
                <button class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 font-medium rounded-lg transition text-sm">
                    Create Invoice
                </button>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="border-b border-slate-200 dark:border-slate-800">
        <div class="flex gap-8 overflow-x-auto">
            <button @click="tab = 'overview'" :class="tab === 'overview' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Overview
            </button>
            <button @click="tab = 'services'" :class="tab === 'services' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Services
            </button>
            <button @click="tab = 'invoices'" :class="tab === 'invoices' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Invoices
            </button>
            <button @click="tab = 'payments'" :class="tab === 'payments' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Payments
            </button>
            <button @click="tab = 'tickets'" :class="tab === 'tickets' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Tickets
            </button>
            <button @click="tab = 'activity'" :class="tab === 'activity' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Activity
            </button>
        </div>
    </div>

    <!-- Tab Content -->

    <!-- Overview Tab -->
    <div x-show="tab === 'overview'" class="space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Profile Info Card -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Profile Information</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Email Address</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->email }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Phone</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->phone ?: '-' }}</p>
                    </div>
                    @if ($customer->company)
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Company</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->company }}</p>
                        </div>
                    @endif
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Country</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->country ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Address</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->address ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">City</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->city ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Postal Code</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->postal_code ?: '-' }}</p>
                    </div>
                    @if ($customer->vat_number)
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">VAT Number</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->vat_number }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Account Summary Card -->
            <div class="space-y-6">
                <!-- Stats -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Account Summary</h3>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                            <p class="text-sm text-slate-600 dark:text-slate-400">Member Since</p>
                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $customer->created_at->format('M d, Y') }}</p>
                        </div>
                        <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                            <p class="text-sm text-slate-600 dark:text-slate-400">Active Services</p>
                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $customer->services_count ?? 0 }}</p>
                        </div>
                        <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                            <p class="text-sm text-slate-600 dark:text-slate-400">Total Invoices</p>
                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $customer->invoices_count ?? 0 }}</p>
                        </div>
                        <div class="flex items-center justify-between">
                            <p class="text-sm text-slate-600 dark:text-slate-400">Outstanding Balance</p>
                            <p class="text-sm font-medium text-amber-600 dark:text-amber-400">$0.00</p>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                @if ($customer->notes)
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Notes</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400">{{ $customer->notes }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Services Tab -->
    <div x-show="tab === 'services'" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            @if ($customer->services->count() > 0)
                <table class="w-full">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Service</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Product</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Created</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($customer->services as $service)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4 text-sm text-slate-900 dark:text-white font-medium">{{ $service->id }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $service->product->name ?? '-' }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                                        Active
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $service->created_at->format('M d, Y') }}</td>
                                <td class="px-6 py-4 text-right">
                                    <a href="#" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 text-sm font-medium">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="px-6 py-12 text-center">
                    <p class="text-slate-600 dark:text-slate-400">No services found.</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Invoices Tab -->
    <div x-show="tab === 'invoices'" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            @if ($customer->invoices->count() > 0)
                <table class="w-full">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Invoice Number</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Date</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Due Date</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Total</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($customer->invoices as $invoice)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">#{{ str_pad($invoice->id, 5, '0', STR_PAD_LEFT) }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $invoice->created_at->format('M d, Y') }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $invoice->due_at ? $invoice->due_at->format('M d, Y') : '-' }}</td>
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">${{ number_format($invoice->total, 2) }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400">
                                        Draft
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="#" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 text-sm font-medium">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="px-6 py-12 text-center">
                    <p class="text-slate-600 dark:text-slate-400">No invoices found.</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Payments Tab -->
    <div x-show="tab === 'payments'" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            @if ($customer->payments->count() > 0)
                <table class="w-full">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Date</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Amount</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Payment Method</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($customer->payments as $payment)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $payment->created_at->format('M d, Y') }}</td>
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">${{ number_format($payment->amount, 2) }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ ucfirst($payment->gateway ?? 'Manual') }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                                        Completed
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="#" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 text-sm font-medium">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="px-6 py-12 text-center">
                    <p class="text-slate-600 dark:text-slate-400">No payments found.</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Tickets Tab -->
    <div x-show="tab === 'tickets'" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            @if ($customer->tickets->count() > 0)
                <table class="w-full">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Ticket ID</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Subject</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Priority</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Created</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($customer->tickets as $ticket)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">#{{ str_pad($ticket->id, 4, '0', STR_PAD_LEFT) }}</td>
                                <td class="px-6 py-4 text-sm text-slate-900 dark:text-white">{{ $ticket->subject }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400">
                                        Normal
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400">
                                        Open
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $ticket->created_at->format('M d, Y') }}</td>
                                <td class="px-6 py-4 text-right">
                                    <a href="#" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 text-sm font-medium">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="px-6 py-12 text-center">
                    <p class="text-slate-600 dark:text-slate-400">No tickets found.</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Activity Tab -->
    <div x-show="tab === 'activity'" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="text-center py-12">
            <p class="text-slate-600 dark:text-slate-400">Full activity log coming soon.</p>
        </div>
    </div>
</div>
@endsection
