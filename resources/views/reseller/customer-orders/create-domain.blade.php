@extends('layouts.reseller')

@section('title', 'Register Domain for Customer')

@section('content')
<div class="space-y-6 max-w-xl" x-data="{ billCustomer: @js((bool) old('bill_customer', true)) }">
    <div>
        <a href="{{ $selectedCustomer ? route('reseller.customers.show', $selectedCustomer) : route('reseller.customers.index') }}" class="text-sm text-purple-600">← Customer</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">Add domain for customer</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Register a new domain on your customer’s account. Choose whether they receive an invoice at your retail price.</p>
    </div>

    <form method="POST" action="{{ route('reseller.customer-orders.domain.store') }}" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium mb-2">Customer</label>
            <select name="customer_id" required class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}" @selected(old('customer_id', $selectedCustomer?->id) == $customer->id)>{{ $customer->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Domain name</label>
            <input type="text" name="domain" value="{{ old('domain') }}" required pattern="[a-zA-Z0-9-]+" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800" placeholder="example">
            <p class="text-xs text-slate-500 mt-1">Label only — pick the extension below.</p>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Extension</label>
            <select name="extension_id" required class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                @foreach ($extensions as $ext)
                    <option value="{{ $ext->id }}" @selected(old('extension_id') == $ext->id)>{{ $ext->extension }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Years</label>
            <select name="years" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                @foreach ([1,2,3,5,10] as $y)
                    <option value="{{ $y }}" @selected(old('years', 1) == $y)>{{ $y }}</option>
                @endforeach
            </select>
        </div>

        <div class="rounded-lg border border-slate-200 dark:border-slate-700 p-4 space-y-3">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="hidden" name="bill_customer" value="0">
                <input type="checkbox" name="bill_customer" value="1" x-model="billCustomer"
                    class="mt-1 rounded border-slate-300 text-purple-600 focus:ring-purple-500">
                <span>
                    <span class="block text-sm font-medium text-slate-900 dark:text-white">Bill customer at retail price</span>
                    <span class="block text-xs text-slate-500 mt-1">Creates an unpaid customer invoice. Wholesale is debited from your wallet when the domain is pushed after they pay.</span>
                </span>
            </label>

            <div x-show="!billCustomer" x-cloak class="text-xs text-amber-800 dark:text-amber-200 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 rounded-lg p-3">
                <strong>No customer invoice.</strong> Registration is provisioned for free on their account. Platform wholesale is still charged from your reseller wallet when the order is pushed (or queued if balance is low).
            </div>
        </div>

        <p class="text-xs text-slate-500" x-show="billCustomer">Set retail prices under <a href="{{ route('reseller.domains.pricing') }}" class="text-purple-600">Domain Pricing</a> before billing.</p>

        <div class="flex flex-wrap gap-3 pt-2">
            <button type="submit" class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium"
                x-text="billCustomer ? 'Create order & invoice' : 'Provision without billing'"></button>
            @if ($selectedCustomer)
                <a href="{{ route('reseller.domains.index', ['customer' => $selectedCustomer->id]) }}" class="px-6 py-2.5 border border-purple-300 text-purple-700 rounded-lg font-medium text-sm self-center">Add multiple via cart →</a>
            @endif
        </div>
    </form>
</div>
@endsection
