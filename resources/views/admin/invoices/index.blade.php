@extends('layouts.admin')

@section('title', 'Invoices')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Invoices</p>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Invoices</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage customer invoices and billing records.</p>
        </div>
        <a href="{{ route('admin.invoices.create') }}" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Create Invoice
        </a>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Invoice #, customer name, email..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <option value="all">All Status</option>
                    <option value="draft" @selected(request('status') === 'draft')>Draft</option>
                    <option value="unpaid" @selected(request('status') === 'unpaid')>Unpaid</option>
                    <option value="paid" @selected(request('status') === 'paid')>Paid</option>
                    <option value="overdue" @selected(request('status') === 'overdue')>Overdue</option>
                    <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">Filter</button>
            </div>
        </div>
    </form>

    <!-- Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Invoice #</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Customer</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Amount</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Due Date</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Created</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($invoices as $invoice)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">#{{ $invoice->invoice_number }}</td>
                            <td class="px-6 py-4">
                                <div>
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $invoice->user->name }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">{{ $invoice->user->email }}</p>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">KSH {{ number_format($invoice->total, 2) }}</td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                {{ $invoice->due_date ? $invoice->due_date->format('M d, Y') : '-' }}
                            </td>
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
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $invoice->created_at->format('M d, Y') }}</td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1" x-data="{ deleteModal_{{ $invoice->id }}: false }">
                                    <a href="{{ route('admin.invoices.show', $invoice) }}" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                        View
                                    </a>
                                    <a href="{{ route('admin.invoices.edit', $invoice) }}" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                        Edit
                                    </a>
                                    <button @click="deleteModal_{{ $invoice->id }} = true" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                        Delete
                                    </button>

                                    <!-- Delete Confirmation Modal -->
                                    <div x-show="deleteModal_{{ $invoice->id }}" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="deleteModal_{{ $invoice->id }} = false">
                                        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 max-w-sm mx-4">
                                            <div class="flex items-center gap-3 mb-4">
                                                <div class="flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 dark:bg-red-900/30">
                                                    <svg class="h-6 w-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 4v2M6.343 3L3 18a2 2 0 002 2h14a2 2 0 002-2l-3.343-15"/>
                                                    </svg>
                                                </div>
                                                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Delete Invoice?</h3>
                                            </div>
                                            <p class="text-slate-600 dark:text-slate-400 mb-6 text-sm">Are you sure you want to delete invoice <strong>#{{ $invoice->invoice_number }}</strong>? This will also delete all associated payments and line items. This action cannot be undone.</p>
                                            <div class="flex gap-3 justify-end">
                                                <button @click="deleteModal_{{ $invoice->id }} = false" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-medium rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition text-sm">
                                                    Cancel
                                                </button>
                                                <form action="{{ route('admin.invoices.destroy', $invoice) }}" method="POST" class="inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition text-sm">
                                                        Delete Invoice
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <p class="text-slate-600 dark:text-slate-400">No invoices found.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        {{ $invoices->links() }}
    </div>
</div>
@endsection
