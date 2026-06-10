<div class="space-y-6">
    <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 dark:from-emerald-950/40 dark:to-emerald-900/30 rounded-xl border border-emerald-200 dark:border-emerald-800 p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-emerald-700 dark:text-emerald-300 uppercase tracking-wide">Available credit</p>
                <p class="text-3xl font-bold text-emerald-900 dark:text-emerald-100 mt-1">KES {{ number_format($creditAvailableBalance, 2) }}</p>
                <p class="text-xs text-emerald-800 dark:text-emerald-400 mt-2">Credits apply automatically at checkout and on invoice payment.</p>
            </div>
            <a href="{{ route('admin.credits.index', ['search' => $customer->email]) }}" class="self-start px-4 py-2 text-sm font-medium text-emerald-700 dark:text-emerald-300 bg-white/80 dark:bg-slate-900/50 border border-emerald-200 dark:border-emerald-700 rounded-lg hover:bg-white dark:hover:bg-slate-900 transition">
                All credits
            </a>
        </div>
    </div>

    <div class="bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Add credit</h3>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Issue manual account credit for this customer. They can use it when checking out or paying invoices.</p>

        <form method="POST" action="{{ route('admin.customers.add-credit', $customer) }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="credit_amount" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Amount (KES)</label>
                    <input
                        type="number"
                        id="credit_amount"
                        name="amount"
                        step="0.01"
                        min="0.01"
                        required
                        value="{{ old('amount') }}"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500 @error('amount') border-red-500 @enderror"
                        placeholder="e.g. 1000"
                    >
                    @error('amount')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="credit_source" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Source</label>
                    <select id="credit_source" name="source" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500">
                        @foreach(['admin' => 'Admin adjustment', 'promotion' => 'Promotion', 'refund' => 'Refund'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('source', 'admin') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('source')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="credit_expires_at" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Expires (optional)</label>
                    <input
                        type="date"
                        id="credit_expires_at"
                        name="expires_at"
                        value="{{ old('expires_at') }}"
                        min="{{ now()->addDay()->toDateString() }}"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500"
                    >
                    @error('expires_at')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="credit_notes" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Notes</label>
                    <input
                        type="text"
                        id="credit_notes"
                        name="notes"
                        value="{{ old('notes') }}"
                        maxlength="500"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500"
                        placeholder="e.g. Goodwill credit for billing issue"
                    >
                    @error('notes')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <button type="submit" class="px-6 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg transition">
                Add credit
            </button>
        </form>
    </div>

    <div>
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Credit history</h3>
        @if ($customerCredits->isNotEmpty())
            <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Date</th>
                            <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Amount</th>
                            <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Available</th>
                            <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Source</th>
                            <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Notes</th>
                            <th class="text-right py-3 px-4 font-semibold text-slate-900 dark:text-white">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800 bg-white dark:bg-slate-900">
                        @foreach ($customerCredits as $credit)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="py-3 px-4 text-slate-600 dark:text-slate-400 whitespace-nowrap">{{ $credit->created_at->format('M d, Y H:i') }}</td>
                                <td class="py-3 px-4 font-medium text-slate-900 dark:text-white">KES {{ number_format($credit->amount, 2) }}</td>
                                <td class="py-3 px-4 text-emerald-700 dark:text-emerald-300">KES {{ number_format($credit->getAvailableBalance(), 2) }}</td>
                                <td class="py-3 px-4 text-slate-600 dark:text-slate-400">{{ ucfirst($credit->source) }}</td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ match($credit->status) {
                                        'active' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300',
                                        'applied' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300',
                                        'expired' => 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400',
                                        default => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
                                    } }}">
                                        {{ ucfirst($credit->status) }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-slate-600 dark:text-slate-400 max-w-xs truncate" title="{{ $credit->notes }}">{{ $credit->notes ?: '—' }}</td>
                                <td class="py-3 px-4 text-right">
                                    <a href="{{ route('admin.credits.show', $credit) }}" class="text-blue-600 dark:text-blue-400 hover:underline">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-6 py-12 text-center">
                <p class="text-slate-600 dark:text-slate-400">No credits issued yet.</p>
            </div>
        @endif
    </div>
</div>
