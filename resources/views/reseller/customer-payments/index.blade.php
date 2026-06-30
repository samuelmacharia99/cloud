@extends('layouts.reseller')

@section('title', 'Payments received')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Dashboard</a>
    <span class="text-slate-400">/</span>
    <span class="text-slate-600 dark:text-slate-400 font-medium">Payments received</span>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <x-reseller-page-header
        title="Payments received"
        description="Customer payments recorded against your retail invoices."
    />

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-500">All-time collected</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1">KSH {{ number_format($totalCollected, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-500">Last 30 days</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1">KSH {{ number_format($collected30d, 2) }}</p>
        </div>
    </div>

    <form method="GET" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Customer, invoice, reference..." class="flex-1 min-w-[12rem] px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
        <select name="status" class="px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
            <option value="all" @selected(request('status', 'all') === 'all')>All statuses</option>
            <option value="completed" @selected(request('status') === 'completed')>Completed</option>
            <option value="pending" @selected(request('status') === 'pending')>Pending</option>
            <option value="failed" @selected(request('status') === 'failed')>Failed</option>
        </select>
        <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-lg">Filter</button>
    </form>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold">Date</th>
                    <th class="px-4 py-3 text-left font-semibold">Customer</th>
                    <th class="px-4 py-3 text-left font-semibold">Invoice</th>
                    <th class="px-4 py-3 text-left font-semibold">Method</th>
                    <th class="px-4 py-3 text-left font-semibold">Reference</th>
                    <th class="px-4 py-3 text-right font-semibold">Amount</th>
                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($payments as $payment)
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <td class="px-4 py-3 text-slate-600">{{ $payment->created_at?->format('M j, Y') }}</td>
                        <td class="px-4 py-3">{{ $payment->invoice?->user?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($payment->invoice)
                                <a href="{{ route('reseller.customer-invoices.show', $payment->invoice) }}" class="text-purple-600 hover:underline">{{ $payment->invoice->invoice_number }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3"><x-payment-badge :method="$payment->payment_method" /></td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $payment->transaction_reference ?: '—' }}</td>
                        <td class="px-4 py-3 text-right font-semibold">KSH {{ number_format((float) $payment->amount, 2) }}</td>
                        <td class="px-4 py-3"><x-status-badge :status="$payment->status" type="payment" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-slate-500">No payments found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $payments->links() }}
</div>
@endsection
