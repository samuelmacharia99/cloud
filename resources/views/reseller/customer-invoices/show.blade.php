@extends('layouts.reseller')

@section('title', 'Invoice '.$invoice->invoice_number)

@section('content')
<div class="space-y-6 max-w-4xl">
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('reseller.customer-invoices.index') }}" class="text-sm text-purple-600">← Back to customer billing</a>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $invoice->invoice_number }}</h1>
            <p class="text-slate-600 dark:text-slate-400">{{ $invoice->user?->name }} · <x-status-badge :status="$invoice->status" type="invoice" /></p>
        </div>
        <a href="{{ route('reseller.customer-invoices.download', $invoice) }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">Download PDF</a>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="flex justify-between mb-6">
            <div>
                <p class="text-sm text-slate-500">Subtotal</p>
                <p class="text-lg font-semibold">KES {{ number_format($invoice->subtotal, 2) }}</p>
            </div>
            <div class="text-right">
                <p class="text-sm text-slate-500">Total</p>
                <p class="text-2xl font-bold text-emerald-600">KES {{ number_format($invoice->total, 2) }}</p>
            </div>
        </div>
        <div class="divide-y divide-slate-200 dark:divide-slate-800">
            @foreach ($invoice->items as $item)
                <div class="py-3 flex justify-between text-sm">
                    <span>{{ $item->description }}</span>
                    <span>KES {{ number_format($item->amount, 2) }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
