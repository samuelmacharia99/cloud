@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Invoices</h1>
            <p class="text-slate-600 mt-1">View and manage your billing history.</p>
        </div>
        @auth
            @if (auth()->user()->is_admin)
                <a href="{{ route('invoices.create') }}" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition-colors">
                    + Create Invoice
                </a>
            @endif
        @endauth
    </div>

    <!-- Invoices Table -->
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Invoice</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Customer</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Amount</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Status</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Due Date</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($invoices as $invoice)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4">
                                <p class="font-semibold text-slate-900">{{ $invoice->invoice_number }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600">{{ $invoice->user->name }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="font-semibold text-slate-900">${{ number_format($invoice->total, 2) }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium {{ $invoice->status === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                                    {{ ucfirst($invoice->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600">{{ $invoice->due_date->format('M d, Y') }}</p>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('invoices.show', $invoice) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <p class="text-slate-500">No invoices found</p>
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
