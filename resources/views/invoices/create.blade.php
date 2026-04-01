@extends('layouts.app')

@section('title', 'Create Invoice')

@section('content')
<div class="max-w-2xl space-y-8">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Create New Invoice</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Generate a new invoice for a customer.</p>
    </div>

    <form action="{{ route('invoices.store') }}" method="POST" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 space-y-6">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Customer</label>
            <select name="user_id" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="">Select a customer...</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                @endforeach
            </select>
            @error('user_id') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Invoice Number</label>
            <input type="text" name="invoice_number" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="INV-001">
            @error('invoice_number') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Due Date</label>
            <input type="date" name="due_date" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            @error('due_date') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Subtotal ($)</label>
            <input type="number" name="subtotal" step="0.01" min="0" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="0.00">
            @error('subtotal') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Tax ($)</label>
            <input type="number" name="tax" step="0.01" min="0" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="0.00">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes</label>
            <textarea name="notes" rows="3" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Invoice notes..."></textarea>
        </div>

        <div class="flex gap-4">
            <button type="submit" class="px-6 py-2.5 rounded-lg bg-blue-600 dark:bg-blue-500 text-white font-medium hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
                Create Invoice
            </button>
            <a href="{{ route('invoices.index') }}" class="px-6 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
