@extends('layouts.admin')

@section('title', 'Credit #'.$credit->id)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.credits.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Credits</a>
    <span class="text-slate-400">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">#{{ $credit->id }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6 max-w-4xl">
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Credit #{{ $credit->id }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">
                <a href="{{ route('admin.customers.show', $credit->user) }}" class="text-blue-600 hover:text-blue-700">{{ $credit->user->name }}</a>
            </p>
        </div>
        <form method="POST" action="{{ route('admin.credits.destroy', $credit) }}" data-confirm="Delete this credit?">
            @csrf
            @method('DELETE')
            <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg">Delete</button>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-500 mb-1">Amount</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white">KES {{ number_format($credit->amount, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-500 mb-1">Status</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ ucfirst($credit->status) }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-500 mb-1">Source</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ ucfirst($credit->source) }}</p>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-3">
        <p class="text-sm"><span class="text-slate-500">Created:</span> {{ $credit->created_at->format('M d, Y H:i') }}</p>
        @if($credit->expires_at)
            <p class="text-sm"><span class="text-slate-500">Expires:</span> {{ $credit->expires_at->format('M d, Y') }}</p>
        @endif
        @if($credit->notes)
            <p class="text-sm"><span class="text-slate-500">Notes:</span> {{ $credit->notes }}</p>
        @endif
        @if($credit->payment)
            <p class="text-sm"><span class="text-slate-500">From payment:</span> <a href="{{ route('admin.payments.show', $credit->payment) }}" class="text-blue-600">#{{ $credit->payment->id }}</a></p>
        @endif
    </div>

    @if($credit->appliedToInvoices->isNotEmpty())
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800">
                <h2 class="font-semibold text-slate-900 dark:text-white">Applied to Invoices</h2>
            </div>
            <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                @foreach($credit->appliedToInvoices as $invoice)
                    <li class="px-6 py-3 flex justify-between text-sm">
                        <a href="{{ route('admin.invoices.show', $invoice) }}" class="text-blue-600">{{ $invoice->invoice_number }}</a>
                        <span class="text-slate-600 dark:text-slate-400">KES {{ number_format($invoice->pivot->amount_applied ?? 0, 2) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
@endsection
