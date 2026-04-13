@extends('layouts.customer')

@section('title', 'My Invoices')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Invoices</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">View and manage your invoices.</p>
    </div>

    <!-- Invoices Table -->
    @if ($invoices->count() > 0)
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Invoice #</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Date</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Due Date</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Amount</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($invoices as $invoice)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $invoice->created_at->format('M d, Y') }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                    {{ $invoice->due_date ? $invoice->due_date->format('M d, Y') : '-' }}
                                </td>
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white text-right">KSH {{ number_format($invoice->total, 2) }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        @if($invoice->status === 'paid')
                                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                                        @elseif($invoice->status === 'unpaid')
                                            bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                                        @elseif($invoice->status === 'draft')
                                            bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                                        @elseif($invoice->status === 'overdue')
                                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                        @elseif($invoice->status === 'cancelled')
                                            bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                                        @else
                                            bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                                        @endif
                                    ">
                                        {{ ucfirst($invoice->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('customer.invoices.show', $invoice) }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                            View
                                        </a>
                                        <a href="{{ route('customer.invoices.download', $invoice) }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                            </svg>
                                            Download
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <div class="mt-6">
            {{ $invoices->links() }}
        </div>
    @else
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-12 text-center">
            <svg class="w-16 h-16 text-slate-300 dark:text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
            <p class="text-slate-600 dark:text-slate-400">You don't have any invoices yet</p>
        </div>
    @endif
</div>
@endsection
