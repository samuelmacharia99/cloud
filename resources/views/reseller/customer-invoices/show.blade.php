@extends('layouts.reseller')

@section('title', 'Invoice '.$invoice->invoice_number)

@section('content')
<div class="space-y-6 max-w-4xl" x-data="{ paymentModal: false }">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('reseller.customer-invoices.index') }}" class="text-sm text-purple-600">← Customer billing</a>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $invoice->invoice_number }}</h1>
            <p class="text-slate-600 dark:text-slate-400">
                <a href="{{ route('reseller.customers.show', $invoice->user) }}" class="text-purple-600 hover:underline">{{ $invoice->user?->name }}</a>
                · <x-status-badge :status="$invoice->status" type="invoice" />
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('reseller.customer-invoices.download', $invoice) }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">PDF</a>
            @if ($canEdit ?? false)
                <a href="{{ route('reseller.customer-invoices.edit', $invoice) }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">Edit</a>
            @endif
            <form method="POST" action="{{ route('reseller.customer-invoices.resend', $invoice) }}" class="inline">
                @csrf
                <button type="submit" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">Email customer</button>
            </form>
            @if ($canRecordPayment ?? false)
                <form method="POST" action="{{ route('reseller.customer-invoices.mark-paid', $invoice) }}" class="inline" onsubmit="return confirm('Mark this invoice fully paid?');">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Mark paid</button>
                </form>
                <button type="button" @click="paymentModal = true" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium">Record payment</button>
            @endif
            @if (!in_array($invoice->status->value ?? $invoice->status, ['paid', 'cancelled']))
                <form method="POST" action="{{ route('reseller.customer-invoices.cancel', $invoice) }}" class="inline" onsubmit="return confirm('Cancel this invoice?');">
                    @csrf
                    <button type="submit" class="px-4 py-2 text-red-600 border border-red-200 rounded-lg text-sm">Cancel</button>
                </form>
            @endif
        </div>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div>
                <p class="text-xs text-slate-500 uppercase">Total</p>
                <p class="text-xl font-bold">KES {{ number_format($invoice->total, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 uppercase">Remaining</p>
                <p class="text-xl font-bold text-amber-600">KES {{ number_format($amountRemaining ?? $invoice->getAmountRemaining(), 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 uppercase">Due</p>
                <p class="text-sm font-medium">{{ $invoice->due_date?->format('M d, Y') ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 uppercase">Issued</p>
                <p class="text-sm font-medium">{{ $invoice->created_at->format('M d, Y') }}</p>
            </div>
        </div>
        <div class="divide-y divide-slate-200 dark:divide-slate-800">
            @foreach ($invoice->items as $item)
                <div class="py-3 flex justify-between text-sm">
                    <span>{{ $item->description }} × {{ $item->quantity }}</span>
                    <span>KES {{ number_format($item->amount, 2) }}</span>
                </div>
            @endforeach
        </div>
    </div>

    @if ($invoice->payments->count())
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="font-semibold mb-4">Payments</h2>
            <ul class="space-y-2 text-sm">
                @foreach ($invoice->payments as $payment)
                    <li class="flex justify-between">
                        <span>{{ $payment->paid_at?->format('M d, Y') ?? $payment->created_at->format('M d, Y') }} · {{ ucfirst($payment->payment_method->value ?? $payment->payment_method) }}</span>
                        <span class="font-medium">KES {{ number_format($payment->amount, 2) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($canRecordPayment ?? false)
        <div x-show="paymentModal" x-cloak class="fixed inset-0 bg-black/50 z-40" @click="paymentModal = false"></div>
        <div x-show="paymentModal" x-cloak class="fixed right-0 top-0 bottom-0 w-full max-w-md bg-white dark:bg-slate-900 shadow-2xl z-50 overflow-y-auto">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex justify-between">
                <h3 class="text-xl font-bold">Record payment</h3>
                <button type="button" @click="paymentModal = false" class="text-slate-500">✕</button>
            </div>
            <form method="POST" action="{{ route('reseller.customer-invoices.add-payment', $invoice) }}" class="p-6 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium mb-2">Amount (KES)</label>
                    <input type="number" name="amount" step="0.01" max="{{ $amountRemaining }}" value="{{ $amountRemaining }}" required class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Method</label>
                    <select name="payment_method" required class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                        @foreach (\App\Enums\PaymentMethod::cases() as $method)
                            <option value="{{ $method->value }}">{{ $method->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Reference</label>
                    <input type="text" name="transaction_reference" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Paid at</label>
                    <input type="date" name="paid_at" value="{{ now()->format('Y-m-d') }}" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Notes</label>
                    <textarea name="notes" rows="2" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800"></textarea>
                </div>
                <button type="submit" class="w-full py-2.5 bg-emerald-600 text-white rounded-lg font-medium">Save payment</button>
            </form>
        </div>
    @endif
</div>
@endsection
