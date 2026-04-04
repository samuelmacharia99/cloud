@extends('layouts.admin')

@section('title', 'Invoice #' . str_pad($invoice->id, 5, '0', STR_PAD_LEFT))

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.invoices.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Invoices</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">#{{ str_pad($invoice->id, 5, '0', STR_PAD_LEFT) }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Invoice #{{ str_pad($invoice->id, 5, '0', STR_PAD_LEFT) }}</h1>
                <p class="text-slate-600 dark:text-slate-400 mt-2">{{ $invoice->user->name }} • {{ $invoice->user->email }}</p>

                <!-- Status badge -->
                <div class="mt-4">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if($invoice->status === 'paid')
                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                        @elseif($invoice->status === 'unpaid')
                            bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                        @elseif($invoice->status === 'draft')
                            bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                        @elseif($invoice->status === 'overdue')
                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                        @elseif($invoice->status === 'cancelled')
                            bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                        @else
                            bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                        @endif
                    ">
                        {{ ucfirst($invoice->status) }}
                    </span>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.invoices.download', $invoice) }}" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 font-medium rounded-lg transition text-sm">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                    Download PDF
                </a>
                <a href="{{ route('admin.invoices.edit', $invoice) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                    Edit Invoice
                </a>
            </div>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Invoice Details -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Invoice Details</h2>
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Invoice Amount</p>
                        <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">${{ number_format($invoice->total, 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Status</p>
                        <p class="text-lg font-semibold text-slate-900 dark:text-white mt-1">{{ ucfirst($invoice->status) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Issue Date</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $invoice->created_at->format('M d, Y') }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Due Date</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $invoice->due_date?->format('M d, Y') ?? 'Not set' }}</p>
                    </div>
                </div>
            </div>

            <!-- Line Items -->
            @if ($invoice->items->count() > 0)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Line Items</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-slate-200 dark:border-slate-700">
                                <tr>
                                    <th class="text-left py-3 px-3 font-medium text-slate-600 dark:text-slate-300">#</th>
                                    <th class="text-left py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Description</th>
                                    <th class="text-right py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Qty</th>
                                    <th class="text-right py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Unit Price</th>
                                    <th class="text-right py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                @foreach ($invoice->items as $item)
                                    <tr>
                                        <td class="py-3 px-3 text-slate-900 dark:text-white">{{ $loop->iteration }}</td>
                                        <td class="py-3 px-3">
                                            <div>
                                                <p class="font-medium text-slate-900 dark:text-white">{{ $item->product->name ?? 'Unknown Product' }}</p>
                                                <p class="text-xs text-slate-600 dark:text-slate-400">{{ $item->description }}</p>
                                            </div>
                                        </td>
                                        <td class="py-3 px-3 text-right text-slate-900 dark:text-white">{{ $item->quantity }}</td>
                                        <td class="py-3 px-3 text-right text-slate-900 dark:text-white">${{ number_format($item->unit_price, 2) }}</td>
                                        <td class="py-3 px-3 text-right font-medium text-slate-900 dark:text-white">${{ number_format($item->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <!-- Totals -->
                    <div class="mt-4 border-t border-slate-200 dark:border-slate-700 pt-4">
                        <div class="flex justify-end gap-16">
                            <div>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Subtotal</p>
                                <p class="font-medium text-slate-900 dark:text-white">${{ number_format($invoice->subtotal, 2) }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Tax</p>
                                <p class="font-medium text-slate-900 dark:text-white">${{ number_format($invoice->tax, 2) }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Total</p>
                                <p class="font-bold text-lg text-slate-900 dark:text-white">${{ number_format($invoice->total, 2) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Payments -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Payments</h2>
                @if ($invoice->payments->count() > 0)
                    <div class="space-y-3">
                        @foreach ($invoice->payments as $payment)
                            <div class="flex items-center justify-between p-3 border border-slate-200 dark:border-slate-800 rounded-lg">
                                <div>
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">${{ number_format($payment->amount, 2) }} via {{ ucfirst($payment->gateway) }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">{{ $payment->created_at->format('M d, Y') }}</p>
                                </div>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    @if($payment->status === 'completed')
                                        bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                                    @elseif($payment->status === 'pending')
                                        bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                                    @elseif($payment->status === 'failed')
                                        bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                    @else
                                        bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                                    @endif
                                ">
                                    {{ ucfirst($payment->status) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-slate-600 dark:text-slate-400">No payments recorded for this invoice.</p>
                @endif
            </div>

            <!-- Notes -->
            @if ($invoice->notes)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Notes</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400">{{ $invoice->notes }}</p>
                </div>
            @endif
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Customer Info -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Customer</h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold">
                            {{ strtoupper(substr($invoice->user->name, 0, 1)) }}
                        </div>
                        <div>
                            <p class="font-medium text-slate-900 dark:text-white">{{ $invoice->user->name }}</p>
                            <p class="text-xs text-slate-600 dark:text-slate-400">{{ $invoice->user->email }}</p>
                        </div>
                    </div>
                    <a href="{{ route('admin.customers.show', $invoice->user) }}" class="block mt-4 px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                        View Customer
                    </a>
                </div>
            </div>

            <!-- Timeline -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Timeline</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-slate-900 dark:text-white">{{ $invoice->created_at->format('M d, Y \a\t h:i A') }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Last Updated</p>
                        <p class="text-slate-900 dark:text-white">{{ $invoice->updated_at->format('M d, Y \a\t h:i A') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
