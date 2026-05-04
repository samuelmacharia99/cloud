@extends('layouts.admin')

@section('title', 'Domain Renewals')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Domain Renewals</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage customer domain renewal orders</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
        <form action="{{ route('admin.domain-renewals.index') }}" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                    <option value="">All Statuses</option>
                    <option value="pending" @selected(request('status') === 'pending')>Pending</option>
                    <option value="invoiced" @selected(request('status') === 'invoiced')>Invoiced</option>
                    <option value="paid" @selected(request('status') === 'paid')>Paid</option>
                    <option value="pushed" @selected(request('status') === 'pushed')>Pushed to Admin</option>
                    <option value="completed" @selected(request('status') === 'completed')>Completed</option>
                    <option value="failed" @selected(request('status') === 'failed')>Failed</option>
                    <option value="expired" @selected(request('status') === 'expired')>Expired</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain</label>
                <input type="text" name="domain" value="{{ request('domain') }}" placeholder="Search domain..." class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Customer</label>
                <input type="text" name="customer" value="{{ request('customer') }}" placeholder="Search customer..." class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                    Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Domain</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Customer</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Period</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Created</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    @forelse($renewals as $renewal)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">
                                {{ $renewal->domain->name }}{{ $renewal->domain->extension }}
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                {{ $renewal->user->name }}
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                {{ $renewal->years }} year{{ $renewal->years > 1 ? 's' : '' }}
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">
                                KES {{ number_format($renewal->amount, 2) }}
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium {{ match($renewal->status) {
                                    'pending' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
                                    'invoiced' => 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
                                    'paid' => 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300',
                                    'pushed' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300',
                                    'completed' => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
                                    'failed' => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
                                    'expired' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
                                    default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                } }}">
                                    {{ ucfirst($renewal->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                {{ $renewal->created_at->format('M d, Y') }}
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <a href="{{ route('admin.domain-renewals.show', $renewal) }}" class="text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 font-medium">
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">
                                No domain renewal orders found
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-700">
            {{ $renewals->links() }}
        </div>
    </div>
</div>
@endsection
