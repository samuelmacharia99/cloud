@extends('layouts.admin')

@section('title', 'Reports')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Reports</p>
@endsection

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Platform Reports</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Revenue, reseller commissions, and customer ownership overview.</p>
    </div>

    <form method="GET" action="{{ route('admin.reports.index') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 flex flex-wrap gap-4 items-end">
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">From</label>
            <input type="date" name="from" value="{{ $from }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">To</label>
            <input type="date" name="to" value="{{ $to }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm">
        </div>
        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg">Apply</button>
    </form>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-500 mb-1">Platform customers</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ $platformCustomers }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-500 mb-1">Reseller-managed</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ $managedCustomers }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-500 mb-1">Revenue (period)</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white">KES {{ number_format($revenueInPeriod, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-500 mb-1">Outstanding invoices</p>
            <p class="text-2xl font-bold text-amber-600">KES {{ number_format($outstandingTotal, 2) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-500 mb-1">Reseller margin (period)</p>
            <p class="text-2xl font-bold text-emerald-600">KES {{ number_format($totals['margin_total'], 2) }}</p>
            <p class="text-xs text-slate-500 mt-1">{{ $totals['entry_count'] }} ledger entries</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-500 mb-1">Retail (period)</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white">KES {{ number_format($totals['retail_total'], 2) }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-500 mb-1">Wholesale (period)</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white">KES {{ number_format($totals['wholesale_total'], 2) }}</p>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Commission by Reseller</h2>
            <p class="text-sm text-slate-500">{{ $resellerCount }} active resellers on platform</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Reseller</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Commission rate</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold">Retail</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold">Wholesale</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold">Margin</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold">Entries</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    @forelse($marginByReseller as $row)
                        <tr>
                            <td class="px-6 py-4">
                                @if($row->reseller)
                                    <a href="{{ route('admin.resellers.show', $row->reseller) }}" class="text-blue-600 font-medium">{{ $row->reseller->name }}</a>
                                @else
                                    <span class="text-slate-500">Unknown</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600">{{ $row->reseller?->commission_rate !== null ? number_format($row->reseller->commission_rate, 1).'%' : '—' }}</td>
                            <td class="px-6 py-4 text-right text-sm">KES {{ number_format($row->retail_total, 2) }}</td>
                            <td class="px-6 py-4 text-right text-sm">KES {{ number_format($row->wholesale_total, 2) }}</td>
                            <td class="px-6 py-4 text-right text-sm font-semibold text-emerald-600">KES {{ number_format($row->margin_total, 2) }}</td>
                            <td class="px-6 py-4 text-right text-sm">{{ $row->entry_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">No margin entries in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Margin Ledger</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold">Date</th>
                        <th class="px-6 py-3 text-left font-semibold">Reseller</th>
                        <th class="px-6 py-3 text-left font-semibold">Customer</th>
                        <th class="px-6 py-3 text-left font-semibold">Description</th>
                        <th class="px-6 py-3 text-right font-semibold">Margin</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    @foreach($ledgerEntries as $entry)
                        <tr>
                            <td class="px-6 py-3 text-slate-600">{{ $entry->created_at->format('M d, Y') }}</td>
                            <td class="px-6 py-3">{{ $entry->reseller?->name ?? '—' }}</td>
                            <td class="px-6 py-3">{{ $entry->customer?->name ?? '—' }}</td>
                            <td class="px-6 py-3 text-slate-600">{{ Str::limit($entry->description, 50) }}</td>
                            <td class="px-6 py-3 text-right font-medium">KES {{ number_format($entry->margin_amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($ledgerEntries->hasPages())
            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800">{{ $ledgerEntries->links() }}</div>
        @endif
    </div>
</div>
@endsection
