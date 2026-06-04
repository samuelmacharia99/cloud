@extends('layouts.reseller')

@section('title', 'Register Domain for Customer')

@section('content')
<div class="space-y-6 max-w-xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Register domain for customer</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Customer is invoiced at your retail price. Wholesale cost is debited from your wallet when the domain is pushed after payment.</p>
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
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Extension</label>
            <select name="extension_id" required class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                @foreach ($extensions as $ext)
                    <option value="{{ $ext->id }}">{{ $ext->extension }}</option>
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

        <p class="text-xs text-slate-500">Set retail prices under Domain Pricing before ordering.</p>

        <div class="flex flex-wrap gap-3 pt-2">
            <button type="submit" class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium">Create single order</button>
            @if ($selectedCustomer)
                <a href="{{ route('reseller.domains.index', ['customer' => $selectedCustomer->id]) }}" class="px-6 py-2.5 border border-purple-300 text-purple-700 rounded-lg font-medium text-sm self-center">Add multiple via cart →</a>
            @endif
        </div>
    </form>
</div>
@endsection
