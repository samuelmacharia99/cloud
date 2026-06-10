@extends('layouts.admin')

@section('title', 'Domain Orders')

@section('content')
<div
    class="space-y-6"
    x-data="{
        completeOrderId: null,
        completeDomain: '',
        failOrderId: null,
        failDomain: '',
    }"
    @open-domain-order-complete.window="completeOrderId = $event.detail.orderId; completeDomain = $event.detail.domain"
    @open-domain-order-fail.window="failOrderId = $event.detail.orderId; failDomain = $event.detail.domain"
>
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Domain Orders</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">View and manage all reseller domain orders.</p>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Domain, reseller, or customer..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
                    <option value="all">All Status</option>
                    <option value="queued" @selected(request('status') === 'queued')>Queued</option>
                    <option value="pushed" @selected(request('status') === 'pushed')>Pushed</option>
                    <option value="completed" @selected(request('status') === 'completed')>Completed</option>
                    <option value="failed" @selected(request('status') === 'failed')>Failed</option>
                    <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
                    <option value="expired" @selected(request('status') === 'expired')>Expired</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Date Range</label>
                <input type="date" name="from_date" value="{{ request('from_date') }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
            </div>

            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">Filter</button>
            </div>
        </form>
    </div>

    <!-- Orders Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Domain</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Reseller</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Customer</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Amount</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Date</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white min-w-[14rem]">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($orders as $order)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors group">
                            <td class="px-6 py-4 font-medium text-slate-900 dark:text-white">{{ $order->fullDomainName() }}</td>
                            <td class="px-6 py-4 text-sm text-slate-900 dark:text-white">{{ $order->reseller->name }}</td>
                            <td class="px-6 py-4 text-sm text-slate-900 dark:text-white">
                                {{ $order->customerLabel() }}
                                @if ($order->isSelfOrder())
                                    <span class="ml-1 text-xs text-purple-600 dark:text-purple-400">(wholesale)</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($order->status) {
                                    'queued' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
                                    'pushed' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300',
                                    'completed' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300',
                                    'failed' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
                                    'cancelled' => 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200',
                                    'expired' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
                                    default => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
                                } }}">
                                    {{ ucfirst($order->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right text-sm font-medium text-slate-900 dark:text-white">KES {{ number_format($order->wholesale_amount, 2) }}</td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $order->created_at->format('M d, Y') }}</td>
                            <td class="px-6 py-4 text-right sticky right-0 z-10 bg-white dark:bg-slate-900 group-hover:bg-slate-50 dark:group-hover:bg-slate-800 shadow-[-8px_0_16px_-12px_rgba(15,23,42,0.12)] dark:shadow-[-8px_0_16px_-12px_rgba(0,0,0,0.35)]">
                                @include('admin.domain-orders.partials.actions', ['order' => $order])
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">No domain orders found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        {{ $orders->links() }}
    </div>

    <!-- Complete modal -->
    <template x-teleport="body">
        <div
            x-show="completeOrderId !== null"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            @keydown.escape.window="completeOrderId = null"
        >
            <div class="absolute inset-0 bg-slate-900/60" @click="completeOrderId = null"></div>
            <div class="relative w-full max-w-md bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Complete domain order</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4" x-text="completeDomain"></p>
                @foreach ($orders as $order)
                    <form
                        x-show="completeOrderId === {{ $order->id }}"
                        method="POST"
                        action="{{ route('admin.domain-orders.complete', $order) }}"
                        class="space-y-4"
                    >
                        @csrf
                        @foreach (request()->only(['search', 'status', 'reseller_id', 'from_date', 'to_date', 'page']) as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endforeach
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registrar</label>
                            <input type="text" name="registrar" required placeholder="e.g., GoDaddy, Namecheap" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm">
                        </div>
                        <div class="flex gap-2 justify-end">
                            <button type="button" @click="completeOrderId = null" class="px-4 py-2 text-sm font-medium rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">Close</button>
                            <button type="submit" class="px-4 py-2 text-sm font-medium rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white">Mark completed</button>
                        </div>
                    </form>
                @endforeach
            </div>
        </div>
    </template>

    <!-- Fail modal -->
    <template x-teleport="body">
        <div
            x-show="failOrderId !== null"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            @keydown.escape.window="failOrderId = null"
        >
            <div class="absolute inset-0 bg-slate-900/60" @click="failOrderId = null"></div>
            <div class="relative w-full max-w-md bg-white dark:bg-slate-900 rounded-2xl border border-red-200 dark:border-red-800 shadow-xl p-6">
                <h3 class="text-lg font-semibold text-red-900 dark:text-red-200 mb-1">Mark order as failed</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4" x-text="failDomain"></p>
                @foreach ($orders as $order)
                    <form
                        x-show="failOrderId === {{ $order->id }}"
                        method="POST"
                        action="{{ route('admin.domain-orders.fail', $order) }}"
                        class="space-y-4"
                    >
                        @csrf
                        @foreach (request()->only(['search', 'status', 'reseller_id', 'from_date', 'to_date', 'page']) as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endforeach
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Failure reason</label>
                            <textarea name="failure_reason" required rows="3" class="w-full px-4 py-2 border border-red-300 dark:border-red-600 bg-white dark:bg-slate-800 rounded-lg text-sm" placeholder="Explain why this order failed..."></textarea>
                        </div>
                        <div class="flex gap-2 justify-end">
                            <button type="button" @click="failOrderId = null" class="px-4 py-2 text-sm font-medium rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">Close</button>
                            <button type="submit" class="px-4 py-2 text-sm font-medium rounded-lg bg-red-600 hover:bg-red-700 text-white">Confirm failure</button>
                        </div>
                    </form>
                @endforeach
            </div>
        </div>
    </template>
</div>
@endsection
