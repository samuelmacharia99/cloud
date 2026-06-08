@extends('layouts.customer')

@section('title', 'Bank Transfer — '.$invoice->invoice_number)

@section('content')
<div class="space-y-6 max-w-3xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Bank Transfer</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Transfer funds to our account, then submit your reference below.</p>
    </div>

    @if ($bankDetails['bank_name'] && $bankDetails['bank_account_number'])
        <div class="ui-card p-6 border-emerald-200 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/20">
            <h2 class="font-semibold text-emerald-900 dark:text-emerald-300 mb-4">Pay to this account</h2>
            <dl class="space-y-3 text-sm">
                <div><dt class="text-slate-500">Bank</dt><dd class="font-semibold">{{ $bankDetails['bank_name'] }}</dd></div>
                @if($bankDetails['bank_account_name'])
                    <div><dt class="text-slate-500">Account name</dt><dd class="font-semibold">{{ $bankDetails['bank_account_name'] }}</dd></div>
                @endif
                <div><dt class="text-slate-500">Account number</dt><dd class="font-mono font-bold text-lg">{{ $bankDetails['bank_account_number'] }}</dd></div>
                <div><dt class="text-slate-500">Amount</dt><dd class="font-bold text-lg text-emerald-700 dark:text-emerald-300">KES {{ number_format($amountRemaining, 2) }}</dd></div>
            </dl>
        </div>
    @else
        <div class="ui-card p-4 border-amber-200 bg-amber-50 dark:bg-amber-950/20 text-amber-800 dark:text-amber-200 text-sm">
            Bank details are not configured. Please contact support.
        </div>
    @endif

    <div class="ui-card p-8">
        <form method="POST" action="{{ route('customer.payment.bank-transfer-submit', $invoice) }}" class="space-y-5">
            @csrf
            <div>
                <label for="payment_reference" class="block text-sm font-medium mb-2">Payment reference / slip number <span class="text-red-500">*</span></label>
                <input type="text" id="payment_reference" name="payment_reference" value="{{ old('payment_reference') }}" required
                       class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 @error('payment_reference') border-red-500 @enderror">
                @error('payment_reference')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="transfer_date" class="block text-sm font-medium mb-2">Transfer date</label>
                <input type="date" id="transfer_date" name="transfer_date" value="{{ old('transfer_date') }}"
                       class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
            </div>
            <div>
                <label for="notes" class="block text-sm font-medium mb-2">Notes</label>
                <textarea id="notes" name="notes" rows="3" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">{{ old('notes') }}</textarea>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn-primary">Submit transfer details</button>
                <a href="{{ route('customer.payment.select-method', $invoice) }}" class="btn-secondary">Back</a>
            </div>
        </form>
    </div>
</div>
@endsection
