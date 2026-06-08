@extends('layouts.customer')

@section('title', 'Account Credits')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Account Credits</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">View your credit balance and transaction history.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="ui-card p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Available Balance</p>
            <p class="text-3xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">KES {{ number_format($availableBalance, 2) }}</p>
        </div>
        <div class="ui-card p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Active Credit Entries</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $activeCredits->count() }}</p>
        </div>
    </div>

    <form method="GET" class="ui-card p-4 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Status</label>
            <select name="status" class="form-select text-sm">
                <option value="all" @selected(request('status', 'all') === 'all')>All</option>
                <option value="active" @selected(request('status') === 'active')>Active</option>
                <option value="applied" @selected(request('status') === 'applied')>Applied</option>
                <option value="expired" @selected(request('status') === 'expired')>Expired</option>
                <option value="refunded" @selected(request('status') === 'refunded')>Refunded</option>
            </select>
        </div>
        <button type="submit" class="btn-primary btn-sm">Filter</button>
        @if(request()->hasAny(['status']))
            <a href="{{ route('customer.credits.index') }}" class="btn-secondary btn-sm">Clear</a>
        @endif
    </form>

    <div class="ui-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Date</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Amount</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Source</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Status</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Expires</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($credits as $credit)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-6 py-4 text-sm">{{ $credit->created_at->format('M d, Y') }}</td>
                            <td class="px-6 py-4 text-sm font-medium">KES {{ number_format($credit->amount, 2) }}</td>
                            <td class="px-6 py-4 text-sm capitalize">{{ str_replace('_', ' ', $credit->source) }}</td>
                            <td class="px-6 py-4 text-sm capitalize">{{ $credit->status }}</td>
                            <td class="px-6 py-4 text-sm">{{ $credit->expires_at?->format('M d, Y') ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ Str::limit($credit->notes, 40) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-500">No credits on your account yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($credits->hasPages())
            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800">{{ $credits->links() }}</div>
        @endif
    </div>
</div>
@endsection
