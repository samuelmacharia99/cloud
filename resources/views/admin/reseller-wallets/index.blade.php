@extends('layouts.admin')

@section('title', 'Reseller Wallets')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Reseller Wallets</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Monitor and manage all reseller wallet balances and transactions.</p>
    </div>

    <!-- Search & Filter -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <form method="GET" class="flex gap-4">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Reseller name or email..." class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400">
            <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">Search</button>
        </form>
    </div>

    <!-- Wallets Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Reseller</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Balance</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Threshold</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Auto Push</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($resellers as $reseller)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4">
                                <div>
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $reseller->name }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">{{ $reseller->email }}</p>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right font-semibold text-slate-900 dark:text-white">
                                KES {{ number_format($reseller->wallet?->balance ?? 0, 2) }}
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($reseller->wallet?->status ?? 'active') {
                                    'active' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300',
                                    'suspended' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
                                    'frozen' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
                                    default => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'
                                } }}">
                                    {{ ucfirst($reseller->wallet?->status ?? 'no-wallet') }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                KES {{ number_format($reseller->wallet?->low_balance_threshold ?? 0, 2) }}
                            </td>
                            <td class="px-6 py-4 text-sm">
                                @if($reseller->wallet?->auto_push_enabled)
                                <span class="text-emerald-600 dark:text-emerald-400">✓ Enabled</span>
                                @else
                                <span class="text-slate-500 dark:text-slate-400">✗ Disabled</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('admin.reseller-wallets.show', $reseller) }}" class="text-purple-600 dark:text-purple-400 hover:underline text-sm font-medium">View Details</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">No resellers found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        {{ $resellers->links() }}
    </div>
</div>
@endsection
