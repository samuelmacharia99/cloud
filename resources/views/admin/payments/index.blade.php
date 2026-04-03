@extends('layouts.admin')

@section('title', 'Payments')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Payments</p>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Payments</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Track and manage payment transactions.</p>
        </div>
        <a href="{{ route('admin.payments.create') }}" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Record Payment
        </a>
    </div>

    <!-- Filters -->
    <form method="GET" action="{{ route('admin.payments.index') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-7 gap-4">
            <x-form-select
                label="User"
                name="user_id"
                :options="$users->pluck('name', 'id')->prepend('All Users', '')"
                :value="$filters['user_id'] ?? null"
                placeholder="All users"
            />

            <x-form-select
                label="Payment Method"
                name="payment_method"
                :options="$paymentMethods"
                :value="$filters['payment_method'] ?? null"
                placeholder="All methods"
            />

            <x-form-select
                label="Status"
                name="status"
                :options="$statuses"
                :value="$filters['status'] ?? null"
                placeholder="All statuses"
            />

            <x-form-input
                label="From Date"
                name="from_date"
                type="date"
                :value="$filters['from_date'] ?? null"
            />

            <x-form-input
                label="To Date"
                name="to_date"
                type="date"
                :value="$filters['to_date'] ?? null"
            />

            <div class="flex items-end gap-2">
                <button type="submit" class="px-4 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition-colors font-medium">
                    Filter
                </button>
                <a href="{{ route('admin.payments.index') }}" class="px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors font-medium">
                    Clear
                </a>
            </div>
        </div>
    </form>

    <!-- Table -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Reference</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">User</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Method</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Amount</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Invoice</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Date</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($payments as $payment)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="px-6 py-4 text-sm font-mono text-slate-600 dark:text-slate-400">{{ substr($payment->transaction_reference ?? 'N/A', 0, 12) }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-bold text-sm">
                                        {{ substr($payment->user->name, 0, 1) }}
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $payment->user->name }}</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $payment->user->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <x-payment-badge :method="$payment->payment_method" />
                            </td>
                            <td class="px-6 py-4 text-right text-sm font-medium text-slate-900 dark:text-white">
                                <x-currency-formatter :amount="$payment->amount" :currency="$payment->currency" />
                            </td>
                            <td class="px-6 py-4">
                                <x-status-badge :status="$payment->status" type="payment" />
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                @if ($payment->invoice)
                                    <a href="{{ route('admin.invoices.show', $payment->invoice) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                        {{ $payment->invoice->invoice_number }}
                                    </a>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                {{ $payment->created_at->format('M d, Y') }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="{{ route('admin.payments.show', $payment) }}" class="text-blue-600 dark:text-blue-400 hover:underline text-sm font-medium" title="View payment">
                                        View
                                    </a>
                                    <a href="{{ route('admin.payments.edit', $payment) }}" class="text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 text-sm font-medium" title="Edit payment">
                                        Edit
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center">
                                <div class="space-y-2">
                                    <svg class="mx-auto w-12 h-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    <p class="text-slate-600 dark:text-slate-400 font-medium">No payments found</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if ($payments->hasPages())
            <div class="border-t border-slate-200 dark:border-slate-800 px-6 py-4">
                {{ $payments->links() }}
            </div>
        @endif
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        {{ $payments->links() }}
    </div>
</div>
@endsection
