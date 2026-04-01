@extends('layouts.app')

@section('title', 'Invoice ' . $invoice->invoice_number)

@section('content')
<div class="space-y-8">
    <div>
        <a href="{{ route('invoices.index') }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">← Back to invoices</a>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <!-- Invoice Header -->
        <div class="flex items-start justify-between pb-8 border-b border-slate-200 dark:border-slate-800">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</h1>
                <p class="text-slate-600 dark:text-slate-400 mt-1">Invoice Date: {{ $invoice->created_at->format('M d, Y') }}</p>
            </div>
            <div class="text-right">
                <p class="text-sm font-medium text-slate-600 dark:text-slate-400 uppercase mb-2">Status</p>
                <span class="inline-block px-3 py-1 rounded-full text-sm font-medium {{ $invoice->status === 'paid' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-200' : 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-200' }}">
                    {{ ucfirst($invoice->status) }}
                </span>
            </div>
        </div>

        <!-- Customer & Dates -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 py-8 border-b border-slate-200 dark:border-slate-800">
            <div>
                <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase mb-2">Bill To</p>
                <p class="font-semibold text-slate-900 dark:text-white">{{ $invoice->user->name }}</p>
                <p class="text-slate-600 dark:text-slate-400">{{ $invoice->user->email }}</p>
                @if ($invoice->user->company)
                    <p class="text-slate-600 dark:text-slate-400">{{ $invoice->user->company }}</p>
                @endif
            </div>
            <div class="md:text-right">
                <div class="mb-4">
                    <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase mb-1">Due Date</p>
                    <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ $invoice->due_date->format('M d, Y') }}</p>
                </div>
                @if ($invoice->paid_date)
                    <div>
                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase mb-1">Paid On</p>
                        <p class="text-lg font-semibold text-emerald-600 dark:text-emerald-400">{{ $invoice->paid_date->format('M d, Y') }}</p>
                    </div>
                @endif
            </div>
        </div>

        <!-- Line Items -->
        <div class="py-8 border-b border-slate-200 dark:border-slate-800">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-800">
                        <th class="text-left text-xs font-semibold text-slate-900 dark:text-white uppercase pb-3">Description</th>
                        <th class="text-right text-xs font-semibold text-slate-900 dark:text-white uppercase pb-3">Qty</th>
                        <th class="text-right text-xs font-semibold text-slate-900 dark:text-white uppercase pb-3">Price</th>
                        <th class="text-right text-xs font-semibold text-slate-900 dark:text-white uppercase pb-3">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="py-3 text-slate-900 dark:text-white">{{ $item->description }}</td>
                            <td class="text-right py-3 text-slate-600 dark:text-slate-400">{{ $item->quantity }}</td>
                            <td class="text-right py-3 text-slate-600 dark:text-slate-400">${{ number_format($item->unit_price, 2) }}</td>
                            <td class="text-right py-3 font-medium text-slate-900 dark:text-white">${{ number_format($item->amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Summary -->
        <div class="flex justify-end py-8">
            <div class="w-full md:w-64">
                <div class="flex justify-between mb-3">
                    <span class="text-slate-600 dark:text-slate-400">Subtotal</span>
                    <span class="text-slate-900 dark:text-white">${{ number_format($invoice->subtotal, 2) }}</span>
                </div>
                @if ($invoice->tax > 0)
                    <div class="flex justify-between mb-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                        <span class="text-slate-600 dark:text-slate-400">Tax</span>
                        <span class="text-slate-900 dark:text-white">${{ number_format($invoice->tax, 2) }}</span>
                    </div>
                @endif
                <div class="flex justify-between">
                    <span class="font-semibold text-slate-900 dark:text-white">Total Due</span>
                    <span class="text-2xl font-bold text-slate-900 dark:text-white">${{ number_format($invoice->total, 2) }}</span>
                </div>
            </div>
        </div>

        <!-- Notes -->
        @if ($invoice->notes)
            <div class="pt-8 border-t border-slate-200 dark:border-slate-800">
                <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase mb-2">Notes</p>
                <p class="text-slate-700 dark:text-slate-300">{{ $invoice->notes }}</p>
            </div>
        @endif

        <!-- Actions -->
        @auth
            @if (auth()->user()->is_admin || auth()->user()->id === $invoice->user_id)
                <div class="mt-8 flex gap-4">
                    @if ($invoice->status !== 'paid')
                        <button onclick="alert('Payment processing will be implemented soon')" class="px-6 py-2.5 rounded-lg bg-emerald-600 dark:bg-emerald-500 text-white font-medium hover:bg-emerald-700 dark:hover:bg-emerald-600 transition-colors">
                            Pay Invoice
                        </button>
                    @endif
                    <button onclick="window.print()" class="px-6 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        Print
                    </button>
                </div>
            @endif
        @endauth
    </div>
</div>
@endsection
