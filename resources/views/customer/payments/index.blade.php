@extends('layouts.customer')

@section('title', 'My Payments')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Payments</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Track your payment history and receipts.</p>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Payments</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white mt-2">{{ $payments->total() }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Paid</p>
            <div class="mt-2">
                <x-currency-formatter :amount="$payments->sum('amount')" currency="KES" class="text-2xl font-bold" />
            </div>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Completed</p>
            <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-2">{{ $payments->where('status', 'completed')->count() }}</p>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Amount</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Method</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Invoice</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Date</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold text-slate-900 dark:text-white">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($payments as $payment)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">
                                <x-currency-formatter :amount="$payment->amount" :currency="$payment->currency" />
                            </td>
                            <td class="px-6 py-4">
                                <x-payment-badge :method="$payment->payment_method" />
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                @if ($payment->invoice)
                                    <a href="{{ route('customer.invoices.show', $payment->invoice) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                        {{ $payment->invoice->invoice_number }}
                                    </a>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <x-status-badge :status="$payment->status" type="payment" />
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                {{ $payment->created_at->format('M d, Y') }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                <a href="{{ route('customer.payments.show', $payment) }}" class="text-blue-600 dark:text-blue-400 hover:underline text-sm font-medium">
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <div class="space-y-2">
                                    <svg class="mx-auto w-12 h-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <p class="text-slate-600 dark:text-slate-400 font-medium">No payments yet</p>
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
</div>
@endsection
