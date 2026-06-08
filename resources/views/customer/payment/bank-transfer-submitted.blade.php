@extends('layouts.customer')

@section('title', 'Bank Transfer Submitted')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <div class="ui-card p-8 text-center">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-emerald-100 dark:bg-emerald-950 flex items-center justify-center">
            <svg class="w-8 h-8 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        </div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Transfer details received</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-2">We will confirm your payment once funds are received. Invoice {{ $payment->invoice?->invoice_number }}.</p>
        <p class="mt-4 text-sm">Reference: <span class="font-mono font-semibold">{{ $payment->transaction_reference }}</span></p>
        <p class="text-sm mt-1">Amount: <span class="font-semibold">KES {{ number_format($payment->amount, 2) }}</span></p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('customer.invoices.show', $payment->invoice) }}" class="btn-primary">View invoice</a>
            <a href="{{ route('customer.payments.show', $payment) }}" class="btn-secondary">View payment</a>
        </div>
    </div>
</div>
@endsection
