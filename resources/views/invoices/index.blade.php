@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Invoices</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">View and manage your billing history.</p>
        </div>
        @auth
            @if (auth()->user()->is_admin)
                <a href="{{ route('invoices.create') }}" class="px-6 py-2.5 rounded-lg bg-blue-600 dark:bg-blue-500 text-white text-sm font-medium hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
                    + Create Invoice
                </a>
            @endif
        @endauth
    </div>

    <!-- Invoices Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Invoice</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Customer</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Amount</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Status</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Due Date</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($invoices as $invoice)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="px-6 py-4">
                                <p class="font-semibold text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600 dark:text-slate-400">{{ $invoice->user->name }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="font-semibold text-slate-900 dark:text-white">${{ number_format($invoice->total, 2) }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium {{ $invoice->status === 'paid' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-200' : 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-200' }}">
                                    {{ ucfirst($invoice->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600 dark:text-slate-400">{{ $invoice->due_date->format('M d, Y') }}</p>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('invoices.show', $invoice) }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <p class="text-slate-500 dark:text-slate-400">No invoices found</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($invoices->hasPages())
        <div class="flex items-center justify-center">
            {{ $invoices->links() }}
        </div>
    @endif
</div>
@endsection
