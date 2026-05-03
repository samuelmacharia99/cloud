@extends('layouts.admin')

@section('title', $reseller->name . ' - Wallet')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $reseller->name }}'s Wallet</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">{{ $reseller->email }}</p>
        </div>
        <a href="{{ route('admin.reseller-wallets.export', $reseller) }}" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg transition">
            Export Statement
        </a>
    </div>

    <!-- Balance Card -->
    <div class="bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-950 dark:to-purple-900 rounded-xl border border-purple-200 dark:border-purple-800 p-8">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <p class="text-purple-700 dark:text-purple-300 text-sm font-medium">Current Balance</p>
                <p class="text-3xl font-bold text-purple-900 dark:text-purple-100 mt-2">{{ $wallet->getFormattedBalance() }}</p>
            </div>
            <div>
                <p class="text-purple-700 dark:text-purple-300 text-sm font-medium">Low Balance Threshold</p>
                <p class="text-2xl font-bold text-purple-900 dark:text-purple-100 mt-2">KES {{ number_format($wallet->low_balance_threshold, 2) }}</p>
            </div>
            <div>
                <p class="text-purple-700 dark:text-purple-300 text-sm font-medium">Status</p>
                <p class="text-lg font-bold text-purple-900 dark:text-purple-100 mt-2">{{ ucfirst($wallet->status) }}</p>
            </div>
        </div>
    </div>

    <!-- Adjustment Form -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Adjust Balance</h3>
        <form method="POST" action="{{ route('admin.reseller-wallets.adjust', $reseller) }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Amount (KES)</label>
                    <input type="number" name="amount" step="0.01" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400">
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Use negative value to debit, positive to credit</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Reason</label>
                    <input type="text" name="reason" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400" placeholder="E.g., Manual refund for failed order">
                </div>
            </div>
            <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">Save Adjustment</button>
        </form>
    </div>

    <!-- Transactions Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Transactions</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Date</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Type</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Description</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold text-slate-900 dark:text-white">Amount</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold text-slate-900 dark:text-white">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse($transactions as $transaction)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-3 text-sm text-slate-600 dark:text-slate-400">{{ $transaction->created_at->format('M d, Y H:i') }}</td>
                            <td class="px-6 py-3">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($transaction->type) {
                                    'deposit' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300',
                                    'domain_debit' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
                                    'refund' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300',
                                    'adjustment' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
                                } }}">
                                    {{ ucfirst(str_replace('_', ' ', $transaction->type)) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-sm text-slate-900 dark:text-white">{{ $transaction->description }}</td>
                            <td class="px-6 py-3 text-right text-sm font-medium {{ $transaction->type === 'domain_debit' ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                {{ $transaction->type === 'domain_debit' ? '-' : '+' }}KES {{ number_format($transaction->amount, 2) }}
                            </td>
                            <td class="px-6 py-3 text-right text-sm font-semibold text-slate-900 dark:text-white">KES {{ number_format($transaction->balance_after, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">No transactions found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        {{ $transactions->links() }}
    </div>
</div>
@endsection
