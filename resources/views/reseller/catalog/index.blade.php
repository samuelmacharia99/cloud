@extends('layouts.reseller')

@section('title', 'My Catalog')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('reseller.dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Dashboard</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">My Catalog</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Catalog</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Create and manage products for your customers.</p>
        </div>
        <a href="{{ route('reseller.catalog.create') }}" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
            + Add to Catalog
        </a>
    </div>

    <!-- Table Section -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        @if($catalogItems->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800">
                            <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Name</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Type</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Based On</th>
                            <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Wholesale Cost</th>
                            <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">My Price</th>
                            <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Margin</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Status</th>
                            <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach($catalogItems as $item)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">
                                    {{ $item->name }}
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                    {{ Product::typeLabel($item->type) }}
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                    @if($item->isCustom())
                                        <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded text-xs font-medium">Custom</span>
                                    @else
                                        {{ $item->adminProduct?->name ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-right text-slate-600 dark:text-slate-400">
                                    @if($item->isCustom())
                                        <span class="text-slate-400">—</span>
                                    @else
                                        @if($item->adminProduct?->wholesale_monthly_price)
                                            ${{ number_format($item->adminProduct->wholesale_monthly_price, 2) }}/mo
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-right text-slate-900 dark:text-white font-medium">
                                    @if($item->monthly_price)
                                        ${{ number_format($item->monthly_price, 2) }}/mo
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-right">
                                    @if($item->getMonthlyMargin() !== null)
                                        <span class="text-emerald-600 dark:text-emerald-400 font-medium">
                                            ${{ number_format($item->getMonthlyMargin(), 2) }}
                                        </span>
                                        <br>
                                        <span class="text-emerald-600 dark:text-emerald-400 text-xs">
                                            ({{ number_format($item->getMonthlyMarginPercent(), 1) }}%)
                                        </span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    @if($item->is_active)
                                        <span class="px-2 py-1 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 rounded text-xs font-medium">Active</span>
                                    @else
                                        <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded text-xs font-medium">Inactive</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('reseller.catalog.show', $item) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition" title="View">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                            </svg>
                                        </a>
                                        <a href="{{ route('reseller.catalog.edit', $item) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition" title="Edit">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>
                                        <form method="POST" action="{{ route('reseller.catalog.destroy', $item) }}" class="inline" onsubmit="return confirm('Are you sure?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300 transition" title="Delete">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800">
                {{ $catalogItems->links() }}
            </div>
        @else
            <!-- Empty State -->
            <div class="px-6 py-12 text-center">
                <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4m0 0L4 7m16 0l-8 4m0 0l8 4m-8-4v10m0 0l-8-4m0 0v10"></path>
                </svg>
                <h3 class="mt-4 text-lg font-semibold text-slate-900 dark:text-white">No catalog items yet</h3>
                <p class="mt-1 text-slate-600 dark:text-slate-400">Get started by adding your first product to your catalog.</p>
                <a href="{{ route('reseller.catalog.create') }}" class="mt-4 inline-block px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Add First Product
                </a>
            </div>
        @endif
    </div>
</div>
@endsection
