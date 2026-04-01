@extends('layouts.app')

@section('title', 'Products')

@section('content')
<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Products & Services</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Choose the perfect plan for your needs.</p>
        </div>
        @auth
            @if (auth()->user()->is_admin)
                <a href="{{ route('products.create') }}" class="px-6 py-2.5 rounded-lg bg-blue-600 dark:bg-blue-500 text-white text-sm font-medium hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
                    + Add Product
                </a>
            @endif
        @endauth
    </div>

    <!-- Products Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse ($products as $product)
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden hover:border-slate-300 dark:hover:border-slate-700 transition-all hover:shadow-lg">
                <div class="p-6 space-y-4">
                    <div>
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">{{ ucfirst($product->category) }}</p>
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white mt-1">{{ $product->name }}</h3>
                    </div>

                    <div>
                        <div class="flex items-baseline gap-1">
                            <span class="text-3xl font-bold text-slate-900 dark:text-white">${{ number_format($product->price, 2) }}</span>
                            <span class="text-sm text-slate-600 dark:text-slate-400">/{{ ucfirst($product->billing_cycle) }}</span>
                        </div>
                        @if ($product->setup_fee > 0)
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Setup fee: ${{ number_format($product->setup_fee, 2) }}</p>
                        @endif
                    </div>

                    @if ($product->description)
                        <p class="text-sm text-slate-600 dark:text-slate-400">{{ Str::limit($product->description, 100) }}</p>
                    @endif

                    @if ($product->features)
                        <ul class="space-y-2">
                            @foreach (array_slice($product->features, 0, 3) as $feature)
                                <li class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                                    <svg class="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="p-6 border-t border-slate-200 dark:border-slate-800 flex gap-3">
                    <a href="{{ route('products.show', $product) }}" class="flex-1 text-center px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        Learn more
                    </a>
                    <a href="#" class="flex-1 text-center px-4 py-2 rounded-lg bg-blue-600 dark:bg-blue-500 text-white text-sm font-medium hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
                        Select
                    </a>
                </div>
            </div>
        @empty
            <div class="col-span-full py-12 text-center">
                <p class="text-slate-500 dark:text-slate-400">No products available</p>
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    @if ($products->hasPages())
        <div class="flex items-center justify-center gap-2">
            {{ $products->links() }}
        </div>
    @endif
</div>
@endsection
