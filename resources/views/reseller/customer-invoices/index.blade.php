@extends('layouts.reseller')

@section('title', 'Customer Billing')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Customer Billing</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Invoices issued to your customers.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('reseller.customer-invoices.create') }}" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium">Create invoice</a>
            <a href="{{ route('reseller.customer-orders.hosting.create') }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm font-medium">Order hosting</a>
            <a href="{{ route('reseller.customer-orders.domain.create') }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm font-medium">Register domain</a>
        </div>
    </div>

    <form method="GET" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 flex flex-wrap gap-4">
        <select name="customer" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
            <option value="">All customers</option>
            @foreach ($customers as $customer)
                <option value="{{ $customer->id }}" @selected(request('customer') == $customer->id)>{{ $customer->name }}</option>
            @endforeach
        </select>
        <select name="status" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
            <option value="all">All statuses</option>
            @foreach (['paid','unpaid','overdue'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg">Filter</button>
    </form>

    @if ($invoices->count())
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Invoice</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Customer</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold">Total</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold">Remaining</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Status</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach ($invoices as $invoice)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                            <td class="px-6 py-4 font-medium">{{ $invoice->invoice_number }}</td>
                            <td class="px-6 py-4 text-sm">{{ $invoice->user?->name }}</td>
                            <td class="px-6 py-4 text-right">KSH {{ number_format($invoice->total, 2) }}</td>
                            <td class="px-6 py-4 text-right text-amber-600 font-medium">KSH {{ number_format($invoice->getAmountRemaining(), 2) }}</td>
                            <td class="px-6 py-4"><x-status-badge :status="$invoice->status" type="invoice" /></td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('reseller.customer-invoices.show', $invoice) }}" class="text-purple-600 text-sm font-medium">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $invoices->links() }}
    @else
        <div class="p-12 text-center bg-white dark:bg-slate-900 rounded-2xl border text-slate-500">No customer invoices found.</div>
    @endif
</div>
@endsection
