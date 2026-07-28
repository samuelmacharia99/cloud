<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 flex flex-col">
    <div class="flex items-start justify-between gap-3">
        <h3 class="text-xl font-semibold text-slate-900 dark:text-white">{{ $product->name }}</h3>
        @if ($product->usesDirectAdminPackage())
            <span class="shrink-0 text-[10px] font-bold uppercase tracking-wide px-2 py-1 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">Shared hosting</span>
        @endif
    </div>
    @if ($product->description)
        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 flex-1">{{ $product->description }}</p>
    @else
        <div class="flex-1"></div>
    @endif
    <p class="mt-4 text-2xl font-bold text-blue-600 dark:text-blue-400">
        KES {{ number_format($product->monthly_price ?? 0, 2) }}
        <span class="text-sm font-normal text-slate-500">/mo</span>
    </p>
    @if ($product->yearly_price)
        <p class="text-xs text-slate-500 mt-1">or KES {{ number_format($product->yearly_price, 2) }}/yr</p>
    @endif
    <form action="{{ route('customer.catalog.add', $product) }}" method="POST" class="mt-4 space-y-3">
        @csrf
        <select name="billing_cycle" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-sm text-slate-900 dark:text-white">
            <option value="monthly">Monthly</option>
            <option value="quarterly">Quarterly</option>
            <option value="semi-annual">Semi-annual</option>
            <option value="annual">Annual</option>
        </select>
        <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
            Add to Cart
        </button>
    </form>
</div>
