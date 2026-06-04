@extends('layouts.reseller')

@section('title', 'Order Hosting for Customer')

@section('content')
<div class="space-y-6 max-w-xl" x-data="{ billCustomer: @js((bool) old('bill_customer', true)) }">
    <div>
        <a href="{{ $selectedCustomer ? route('reseller.customers.show', $selectedCustomer) : route('reseller.customer-invoices.index') }}" class="text-sm text-purple-600">← {{ $selectedCustomer ? 'Customer' : 'Customer billing' }}</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">Add hosting for customer</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Create a hosting service from your whitelabel catalog. Choose whether the customer receives an invoice at your retail price.</p>
    </div>

    <form method="POST" action="{{ route('reseller.customer-orders.hosting.store') }}" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-5">
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
            <label class="block text-sm font-medium mb-2">Your catalog product</label>
            <select name="reseller_product_id" required class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                @foreach ($products as $product)
                    <option value="{{ $product->id }}" @selected(old('reseller_product_id') == $product->id)>
                        {{ $product->name }}
                        @if($product->adminProduct) (provisions) @else (invoice only) @endif
                    </option>
                @endforeach
            </select>
            <p class="text-xs text-slate-500 mt-1" x-show="!billCustomer">Complimentary provisioning requires a catalog product linked to a platform plan.</p>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Primary domain</label>
            <input type="text" name="primary_domain" value="{{ old('primary_domain') }}"
                placeholder="customer-site.example.com"
                class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800 font-mono text-sm">
            <p class="text-xs text-slate-500 mt-1">Required for PHP / WordPress (DirectAdmin) plans. Optional for container hosting.</p>
            @error('primary_domain')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Billing cycle</label>
            <select name="billing_cycle" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                @foreach (['monthly', 'quarterly', 'semi-annual', 'annual'] as $cycle)
                    <option value="{{ $cycle }}" @selected(old('billing_cycle', 'monthly') === $cycle)>{{ ucfirst($cycle) }}</option>
                @endforeach
            </select>
        </div>

        <div x-show="billCustomer" x-cloak>
            <label class="block text-sm font-medium mb-2">Order type</label>
            <select name="order_type" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                <option value="provision" @selected(old('order_type', 'provision') === 'provision')>Full order (service + invoice)</option>
                <option value="invoice_only" @selected(old('order_type') === 'invoice_only')>Invoice only (no service)</option>
            </select>
        </div>
        <input type="hidden" name="order_type" value="provision" x-show="!billCustomer" x-cloak>

        <div x-show="billCustomer" x-cloak>
            <label class="block text-sm font-medium mb-2">Due date</label>
            <input type="date" name="due_date" value="{{ old('due_date', now()->addDays(7)->format('Y-m-d')) }}" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Notes</label>
            <textarea name="notes" rows="2" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">{{ old('notes') }}</textarea>
        </div>

        <div class="rounded-lg border border-slate-200 dark:border-slate-700 p-4 space-y-3">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="hidden" name="bill_customer" value="0">
                <input type="checkbox" name="bill_customer" value="1" x-model="billCustomer"
                    class="mt-1 rounded border-slate-300 text-purple-600 focus:ring-purple-500">
                <span>
                    <span class="block text-sm font-medium text-slate-900 dark:text-white">Bill customer at retail price</span>
                    <span class="block text-xs text-slate-500 mt-1">Creates an unpaid invoice. The service provisions automatically when the invoice is paid in full.</span>
                </span>
            </label>

            <div x-show="!billCustomer" x-cloak class="text-xs text-amber-800 dark:text-amber-200 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 rounded-lg p-3">
                <strong>No customer invoice.</strong> A pending service is created and provisioning starts immediately (when auto-provision is enabled). Your platform wholesale cost still applies through normal provisioning.
            </div>
        </div>

        <button type="submit" class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium"
            x-text="billCustomer ? 'Create order & invoice' : 'Provision without billing'"></button>
    </form>
</div>
@endsection
