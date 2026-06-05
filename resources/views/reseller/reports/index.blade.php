@extends('layouts.reseller')

@section('title', 'Whitelabel Reports')

@section('content')
<div class="space-y-8 max-w-6xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Whitelabel reports</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Retail vs wholesale margin across your branded catalog and customer payments.</p>
    </div>

    <form method="GET" class="flex flex-wrap gap-3 items-end bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4">
        <div>
            <label class="block text-xs text-slate-500 mb-1">From</label>
            <input type="date" name="from" value="{{ $from }}" class="px-3 py-2 border rounded-lg bg-white dark:bg-slate-800 text-sm">
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">To</label>
            <input type="date" name="to" value="{{ $to }}" class="px-3 py-2 border rounded-lg bg-white dark:bg-slate-800 text-sm">
        </div>
        <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm">Filter</button>
    </form>

    <div class="grid md:grid-cols-3 gap-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border p-6">
            <p class="text-sm text-slate-500">Earned margin (filtered)</p>
            <p class="text-2xl font-bold text-emerald-600">KSH {{ number_format($ledgerTotals['margin_total'] ?? 0, 2) }}</p>
            <p class="text-xs text-slate-500 mt-2">{{ $ledgerTotals['entry_count'] ?? 0 }} payment line(s)</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-2xl border p-6">
            <p class="text-sm text-slate-500">Customer retail collected</p>
            <p class="text-2xl font-bold">KSH {{ number_format($ledgerTotals['retail_total'] ?? 0, 2) }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-2xl border p-6">
            <p class="text-sm text-slate-500">Outstanding billing</p>
            <p class="text-2xl font-bold text-amber-600">KSH {{ number_format($outstandingBalance, 2) }}</p>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border overflow-hidden">
        <div class="p-6 border-b"><h2 class="font-semibold">Catalog margin (your retail vs wholesale)</h2></div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-800"><tr>
                <th class="px-4 py-3 text-left">Product</th>
                <th class="px-4 py-3 text-right">Monthly margin</th>
                <th class="px-4 py-3 text-right">Annual margin</th>
            </tr></thead>
            <tbody class="divide-y">
                @forelse ($catalogMargins as $row)
                    <tr>
                        <td class="px-4 py-3">{{ $row['name'] }} @if($row['is_custom'])<span class="text-xs text-slate-500">(custom)</span>@endif</td>
                        <td class="px-4 py-3 text-right">{{ $row['monthly_margin'] !== null ? 'KSH '.number_format($row['monthly_margin'], 2) : '—' }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['yearly_margin'] !== null ? 'KSH '.number_format($row['yearly_margin'], 2) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-slate-500">No catalog products yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border overflow-hidden">
        <div class="p-6 border-b flex justify-between items-center">
            <h2 class="font-semibold">Margin ledger (from customer payments)</h2>
            <a href="{{ route('reseller.reports.export.margins', request()->only(['from','to'])) }}" class="text-sm text-purple-600">Export CSV</a>
        </div>
        @if ($ledgerEntries->count())
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800"><tr>
                    <th class="px-4 py-3 text-left">Date</th>
                    <th class="px-4 py-3 text-left">Customer</th>
                    <th class="px-4 py-3 text-left">Description</th>
                    <th class="px-4 py-3 text-right">Margin</th>
                </tr></thead>
                <tbody class="divide-y">
                    @foreach ($ledgerEntries as $entry)
                        <tr>
                            <td class="px-4 py-3">{{ $entry->created_at->format('M d, Y') }}</td>
                            <td class="px-4 py-3">{{ $entry->customer?->name }}</td>
                            <td class="px-4 py-3">{{ $entry->description }}</td>
                            <td class="px-4 py-3 text-right text-emerald-600 font-medium">KSH {{ number_format($entry->margin_amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-4">{{ $ledgerEntries->links() }}</div>
        @else
            <p class="p-8 text-center text-slate-500">No margin recorded yet — entries appear when customer invoices are paid.</p>
        @endif
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border p-6 space-y-3">
        <h2 class="font-semibold">Exports</h2>
        <div class="grid sm:grid-cols-2 gap-2 text-sm">
            <a href="{{ route('reseller.reports.export.customers') }}" class="px-4 py-3 border rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800">Customers</a>
            <a href="{{ route('reseller.reports.export.services') }}" class="px-4 py-3 border rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800">Services</a>
            <a href="{{ route('reseller.reports.export.invoices', request()->only(['from','to'])) }}" class="px-4 py-3 border rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800">Customer invoices</a>
            <a href="{{ route('reseller.reports.export.revenue', request()->only(['from','to'])) }}" class="px-4 py-3 border rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800">Payment revenue</a>
        </div>
    </div>
</div>
@endsection
