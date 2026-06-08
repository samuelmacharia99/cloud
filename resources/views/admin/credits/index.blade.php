@extends('layouts.admin')

@section('title', 'Customer Credits')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Credits</p>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Customer Credits</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage account credits, promotions, and refunds.</p>
        </div>
        <a href="{{ route('admin.credits.create') }}" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
            Issue Credit
        </a>
    </div>

    <form method="GET" action="{{ route('admin.credits.index') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 grid grid-cols-1 md:grid-cols-4 gap-4">
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Customer name or email" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
            <select name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm">
                <option value="all">All statuses</option>
                @foreach(['active', 'applied', 'refunded', 'expired'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Source</label>
            <select name="source" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm">
                <option value="all">All sources</option>
                @foreach(['admin', 'promotion', 'refund', 'overpayment'] as $source)
                    <option value="{{ $source }}" @selected(request('source') === $source)>{{ ucfirst($source) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="px-4 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium">Filter</button>
            <a href="{{ route('admin.credits.index') }}" class="px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-medium">Clear</a>
        </div>
    </form>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">ID</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Customer</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Amount</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Source</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Created</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    @forelse($credits as $credit)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">#{{ $credit->id }}</td>
                            <td class="px-6 py-4">
                                <a href="{{ route('admin.customers.show', $credit->user) }}" class="text-blue-600 hover:text-blue-700 font-medium">{{ $credit->user->name }}</a>
                                <p class="text-xs text-slate-500">{{ $credit->user->email }}</p>
                            </td>
                            <td class="px-6 py-4 text-sm font-semibold text-slate-900 dark:text-white">KES {{ number_format($credit->amount, 2) }}</td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ ucfirst($credit->source) }}</td>
                            <td class="px-6 py-4">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">{{ ucfirst($credit->status) }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $credit->created_at->format('M d, Y') }}</td>
                            <td class="px-6 py-4">
                                <a href="{{ route('admin.credits.show', $credit) }}" class="text-blue-600 hover:text-blue-700 text-sm font-medium">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-slate-500">No credits found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($credits->hasPages())
            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800">{{ $credits->links() }}</div>
        @endif
    </div>
</div>
@endsection
