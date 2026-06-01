<div class="space-y-6">
    <!-- Balance summary -->
    <div class="bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-950/40 dark:to-purple-900/30 rounded-xl border border-purple-200 dark:border-purple-800 p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-purple-700 dark:text-purple-300 uppercase tracking-wide">Wallet balance</p>
                <p class="text-3xl font-bold text-purple-900 dark:text-purple-100 mt-1">{{ $wallet->getFormattedBalance() }}</p>
                @if ($wallet->isLowBalance())
                    <p class="text-xs text-amber-700 dark:text-amber-300 mt-2">Below low-balance threshold ({{ $wallet->currency }} {{ number_format($wallet->low_balance_threshold, 2) }})</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.reseller-wallets.export', $user) }}" class="px-4 py-2 text-sm font-medium text-purple-700 dark:text-purple-300 bg-white/80 dark:bg-slate-900/50 border border-purple-200 dark:border-purple-700 rounded-lg hover:bg-white dark:hover:bg-slate-900 transition">
                    Export statement
                </a>
                <a href="{{ route('admin.reseller-wallets.show', $user) }}" class="px-4 py-2 text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 rounded-lg transition">
                    Full wallet page
                </a>
            </div>
        </div>
    </div>

    <!-- Adjust balance -->
    <div class="bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Adjust balance</h3>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Credit (positive) or debit (negative). The reseller receives SMS and email with previous and new balance.</p>

        <form method="POST" action="{{ route('admin.resellers.wallet-adjust', $user) }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="wallet_amount" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Amount ({{ $wallet->currency }})</label>
                    <input
                        type="number"
                        id="wallet_amount"
                        name="amount"
                        step="0.01"
                        required
                        value="{{ old('amount') }}"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 @error('amount') border-red-500 @enderror"
                        placeholder="e.g. 5000 or -2000"
                    >
                    @error('amount')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Positive = top-up, negative = deduction</p>
                </div>
                <div>
                    <label for="wallet_reason" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Reason</label>
                    <input
                        type="text"
                        id="wallet_reason"
                        name="reason"
                        required
                        minlength="10"
                        maxlength="500"
                        value="{{ old('reason') }}"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 @error('reason') border-red-500 @enderror"
                        placeholder="e.g. Manual top-up per bank transfer REF123"
                    >
                    @error('reason')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                Apply adjustment
            </button>
        </form>
    </div>

    <!-- Transactions -->
    <div>
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Recent transactions</h3>
        @if ($walletTransactions->isNotEmpty())
            <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Date</th>
                            <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Type</th>
                            <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Description</th>
                            <th class="text-right py-3 px-4 font-semibold text-slate-900 dark:text-white">Change</th>
                            <th class="text-right py-3 px-4 font-semibold text-slate-900 dark:text-white">Balance after</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($walletTransactions as $transaction)
                            @php
                                $delta = (float) $transaction->balance_after - (float) $transaction->balance_before;
                                $isCredit = $delta >= 0;
                            @endphp
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="py-3 px-4 text-slate-600 dark:text-slate-400 whitespace-nowrap">{{ $transaction->created_at->format('M d, Y H:i') }}</td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ match($transaction->type) {
                                        'deposit' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300',
                                        'domain_debit' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
                                        'refund' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300',
                                        'adjustment' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
                                        default => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
                                    } }}">
                                        {{ ucfirst(str_replace('_', ' ', $transaction->type)) }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-slate-900 dark:text-white">
                                    {{ $transaction->description }}
                                    @if ($transaction->performer)
                                        <span class="block text-xs text-slate-500 dark:text-slate-400">By {{ $transaction->performer->name }}</span>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-right font-medium {{ $isCredit ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ $isCredit ? '+' : '' }}{{ $wallet->currency }} {{ number_format($delta, 2) }}
                                </td>
                                <td class="py-3 px-4 text-right font-semibold text-slate-900 dark:text-white">
                                    {{ $wallet->currency }} {{ number_format($transaction->balance_after, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-center py-10 text-slate-600 dark:text-slate-400 rounded-xl border border-dashed border-slate-300 dark:border-slate-700">No wallet transactions yet.</p>
        @endif
    </div>
</div>
