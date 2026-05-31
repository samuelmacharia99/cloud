@extends('layouts.customer')

@section('title', 'Reseller Catalog')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Your Reseller Catalog</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Order hosting plans at your reseller's pricing.</p>
    </div>

    @if ($products->count())
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            @foreach ($products as $product)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 flex flex-col">
                    <h2 class="text-xl font-semibold text-slate-900 dark:text-white">{{ $product->name }}</h2>
                    @if ($product->description)
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 flex-1">{{ $product->description }}</p>
                    @endif
                    <p class="mt-4 text-2xl font-bold text-blue-600">KES {{ number_format($product->monthly_price ?? 0, 2) }}<span class="text-sm font-normal text-slate-500">/mo</span></p>
                    @if ($product->product_id)
                        <form action="{{ route('customer.reseller-catalog.add', $product) }}" method="POST" class="mt-4 space-y-3">
                            @csrf
                            <select name="billing_cycle" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-sm">
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="semi-annual">Semi-annual</option>
                                <option value="annual">Annual</option>
                            </select>
                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Add to Cart</button>
                        </form>
                    @else
                        <p class="mt-4 text-sm text-amber-600">Contact your reseller to order this custom plan.</p>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <div class="p-12 text-center bg-white dark:bg-slate-900 rounded-2xl border text-slate-500">Your reseller has not published any catalog items yet.</div>
    @endif
</div>
@endsection
