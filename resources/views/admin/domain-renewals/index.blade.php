@extends('layouts.admin')

@section('title', 'Domain Renewals')

@section('content')
<div
    class="space-y-6"
    x-data="{
        completeRenewalId: null,
        completeDomain: '',
        completeYears: 1,
        currentExpiryIso: null,
        projectedExpiry() {
            const years = parseInt(this.completeYears, 10) || 1;
            const now = new Date();
            let base = this.currentExpiryIso ? new Date(this.currentExpiryIso) : now;
            if (Number.isNaN(base.getTime()) || base < now) {
                base = now;
            }
            const next = new Date(base.getTime());
            next.setFullYear(next.getFullYear() + years);
            return next.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        },
    }"
>
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Domain Renewals</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Renewals only. New registrations and transfers stay on <a href="{{ route('admin.domain-orders.index') }}" class="text-purple-600 dark:text-purple-400 hover:underline">Domain Orders</a>.</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="ui-card p-6">
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
    <div class="ui-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Domain</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Customer</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Period</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Current expiry</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Created</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:border-slate-700">
                    @forelse($renewals as $renewal)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">
                                {{ $renewal->domain->name }}{{ $renewal->domain->extension }}
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                <x-admin.customer-link :user="$renewal->user" />
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                {{ $renewal->years }} year{{ $renewal->years > 1 ? 's' : '' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                {{ $renewal->domain->expires_at?->format('M d, Y') ?? 'N/A' }}
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
                                <div class="inline-flex items-center gap-3">
                                    <a href="{{ route('admin.domain-renewals.show', $renewal) }}" class="text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 font-medium">
                                        View
                                    </a>
                                    @if($renewal->canCompleteManually())
                                        <button
                                            type="button"
                                            class="text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300 font-medium"
                                            @click="completeRenewalId = {{ $renewal->id }}; completeDomain = @js($renewal->domain->name.$renewal->domain->extension); completeYears = {{ (int) $renewal->years }}; currentExpiryIso = @js(optional($renewal->domain->expires_at)?->toIso8601String())"
                                        >
                                            Mark renewed
                                        </button>
                                    @endif
                                    @if($renewal->canPushToRegistrar())
                                        <form method="POST" action="{{ route('admin.domain-renewals.push-registrar', $renewal) }}" class="inline" data-confirm="Submit {{ $renewal->domain->name }}{{ $renewal->domain->extension }} renewal to the API registrar?">
                                            @csrf
                                            <button type="submit" class="text-violet-600 hover:text-violet-700 dark:text-violet-400 dark:hover:text-violet-300 font-medium">
                                                Push to registrar
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">
                                No domain renewal orders found
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-700">
            {{ $renewals->links() }}
        </div>
    </div>

    <template x-teleport="body">
        <div
            x-show="completeRenewalId !== null"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            @keydown.escape.window="completeRenewalId = null"
        >
            <div class="absolute inset-0 bg-slate-900/60" @click="completeRenewalId = null"></div>
            <div class="relative w-full max-w-md ui-card shadow-xl p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Mark domain as renewed</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-1" x-text="completeDomain"></p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Choose how many years to add after the current expiry (or from today if it has already expired).</p>
                @foreach ($renewals as $renewal)
                    @if($renewal->canCompleteManually())
                        <form
                            x-show="completeRenewalId === {{ $renewal->id }}"
                            method="POST"
                            action="{{ route('admin.domain-renewals.complete-manually', $renewal) }}"
                            class="space-y-4"
                        >
                            @csrf
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Renewal period</label>
                                <select name="years" x-model="completeYears" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm">
                                    @foreach($availableYears as $year)
                                        <option value="{{ $year }}">{{ $year }} year{{ $year > 1 ? 's' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">New expiry</label>
                                <p class="px-4 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-sm font-medium text-slate-900 dark:text-white" x-text="projectedExpiry()"></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Admin notes (optional)</label>
                                <textarea name="admin_notes" rows="2" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm" placeholder="Registrar reference..."></textarea>
                            </div>
                            <input type="hidden" name="send_notification" value="1">
                            <div class="flex gap-2 justify-end">
                                <button type="button" @click="completeRenewalId = null" class="px-4 py-2 text-sm font-medium rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">Close</button>
                                <button type="submit" class="px-4 py-2 text-sm font-medium rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white">Mark as renewed</button>
                            </div>
                        </form>
                    @endif
                @endforeach
            </div>
        </div>
    </template>
</div>
@endsection
