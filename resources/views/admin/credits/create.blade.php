@extends('layouts.admin')

@section('title', 'Issue Credit')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.credits.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Credits</a>
    <span class="text-slate-400">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Issue Credit</p>
</div>
@endsection

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Issue Credit</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Add a manual credit to a customer account.</p>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <form method="POST" action="{{ route('admin.credits.store') }}" class="space-y-6">
            @csrf

            <div>
                <label for="user_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Customer</label>
                <select id="user_id" name="user_id" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm">
                    <option value="">Select customer...</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(old('user_id') == $customer->id)>{{ $customer->name }} ({{ $customer->email }})</option>
                    @endforeach
                </select>
                @error('user_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="amount" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Amount (KES)</label>
                <input type="number" step="0.01" min="0.01" id="amount" name="amount" value="{{ old('amount') }}" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm">
                @error('amount')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="source" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Source</label>
                <select id="source" name="source" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm">
                    @foreach(['admin' => 'Admin adjustment', 'promotion' => 'Promotion', 'refund' => 'Refund'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('source') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('source')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="expires_at" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Expires (optional)</label>
                <input type="date" id="expires_at" name="expires_at" value="{{ old('expires_at') }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm">
                @error('expires_at')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes (optional)</label>
                <textarea id="notes" name="notes" rows="3" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm">{{ old('notes') }}</textarea>
            </div>

            <div class="flex gap-3 justify-end">
                <a href="{{ route('admin.credits.index') }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-700 dark:text-slate-300 font-medium">Cancel</a>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg">Issue Credit</button>
            </div>
        </form>
    </div>
</div>
@endsection
