@extends('layouts.app')

@section('title', 'Edit Invoice')

@section('content')
<div class="max-w-2xl space-y-8">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Edit Invoice</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Update invoice details and status.</p>
    </div>

    <form action="{{ route('invoices.update', $invoice) }}" method="POST" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 space-y-6">
        @csrf
        @method('PUT')

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Status</label>
            <select name="status" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="unpaid" {{ $invoice->status === 'unpaid' ? 'selected' : '' }}>Unpaid</option>
                <option value="paid" {{ $invoice->status === 'paid' ? 'selected' : '' }}>Paid</option>
                <option value="overdue" {{ $invoice->status === 'overdue' ? 'selected' : '' }}>Overdue</option>
                <option value="cancelled" {{ $invoice->status === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
            </select>
            @error('status') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Due Date</label>
            <input type="date" name="due_date" value="{{ $invoice->due_date->format('Y-m-d') }}" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            @error('due_date') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes</label>
            <textarea name="notes" rows="3" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">{{ $invoice->notes }}</textarea>
        </div>

        <div class="p-4 rounded-lg bg-slate-100 dark:bg-slate-800">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Subtotal</p>
                    <p class="text-lg font-semibold text-slate-900 dark:text-white">${{ number_format($invoice->subtotal, 2) }}</p>
                </div>
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Tax</p>
                    <p class="text-lg font-semibold text-slate-900 dark:text-white">${{ number_format($invoice->tax, 2) }}</p>
                </div>
                <div class="col-span-2 border-t border-slate-200 dark:border-slate-700 pt-3">
                    <p class="text-sm text-slate-600 dark:text-slate-400">Total</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white">${{ number_format($invoice->total, 2) }}</p>
                </div>
            </div>
        </div>

        <div class="flex gap-4">
            <button type="submit" class="px-6 py-2.5 rounded-lg bg-blue-600 dark:bg-blue-500 text-white font-medium hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
                Update Invoice
            </button>
            <a href="{{ route('invoices.show', $invoice) }}" class="px-6 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
