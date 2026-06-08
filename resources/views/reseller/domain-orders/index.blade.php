@extends('layouts.reseller')

@section('title', 'Domain Orders')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Domain Orders</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">View and manage your customer domain orders.</p>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Domain name or customer..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
                    <option value="all">All Status</option>
                    <option value="queued" @selected(request('status') === 'queued')>Queued</option>
                    <option value="pushed" @selected(request('status') === 'pushed')>Pushed</option>
                    <option value="completed" @selected(request('status') === 'completed')>Completed</option>
                    <option value="failed" @selected(request('status') === 'failed')>Failed</option>
                    <option value="expired" @selected(request('status') === 'expired')>Expired</option>
                    <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
                </select>
            </div>

            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">Filter</button>
            </div>
        </form>
    </div>

    <!-- Orders Table -->
    <div class="ui-card">
        <div class="ui-table-wrap overflow-visible">
            <table class="ui-table min-w-[56rem]">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th class="text-right">Wholesale</th>
                        <th class="hidden lg:table-cell">Created</th>
                        <th class="text-right sticky right-0 z-20 min-w-[11rem] bg-slate-50/95 dark:bg-slate-800/95 backdrop-blur-sm shadow-[-8px_0_16px_-12px_rgba(15,23,42,0.2)] dark:shadow-[-8px_0_16px_-12px_rgba(0,0,0,0.45)]">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        @php
                            $hasActions = $order->status === 'queued'
                                || ($order->status === 'failed' && $order->canRetry())
                                || $order->canCancel()
                                || $order->canDelete();
                        @endphp
                        <tr class="group">
                            <td>
                                <p class="font-semibold text-slate-900 dark:text-white">{{ $order->domain_name }}.{{ $order->extension }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ $order->years }} year(s)</p>
                            </td>
                            <td>
                                <p class="font-medium text-slate-900 dark:text-white">{{ $order->customer->name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 lg:hidden">{{ $order->created_at->format('M d, Y') }}</p>
                            </td>
                            <td>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($order->status) {
                                    'queued' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
                                    'pushed' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300',
                                    'completed' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300',
                                    'failed' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
                                    'expired' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
                                    'cancelled' => 'bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300',
                                    default => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'
                                } }}">
                                    {{ ucfirst($order->status) }}
                                </span>
                                @if($order->status === 'queued' && $order->expires_at)
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Expires {{ $order->expires_at->format('M d') }}</p>
                                @endif
                            </td>
                            <td class="text-right font-medium text-slate-900 dark:text-white whitespace-nowrap">KSH {{ number_format($order->wholesale_amount, 2) }}</td>
                            <td class="hidden lg:table-cell text-slate-600 dark:text-slate-400 whitespace-nowrap">{{ $order->created_at->format('M d, Y') }}</td>
                            <td class="text-right sticky right-0 z-10 min-w-[11rem] bg-white dark:bg-slate-900 group-hover:bg-slate-50 dark:group-hover:bg-slate-800/80 shadow-[-8px_0_16px_-12px_rgba(15,23,42,0.12)] dark:shadow-[-8px_0_16px_-12px_rgba(0,0,0,0.35)]">
                                @if($hasActions)
                                <div class="inline-flex flex-col sm:flex-row flex-wrap items-stretch sm:items-center justify-end gap-1.5">
                                    @if($order->status === 'queued')
                                    <form method="POST" action="{{ route('reseller.domain-orders.push', $order) }}" class="inline" data-confirm="Push this domain order to admin now?">
                                        @csrf
                                        <button type="submit" class="w-full sm:w-auto px-3 py-1.5 text-xs font-semibold rounded-lg text-white bg-blue-600 hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600 transition whitespace-nowrap">
                                            Push
                                        </button>
                                    </form>
                                    @elseif($order->status === 'failed' && $order->canRetry())
                                    <form method="POST" action="{{ route('reseller.domain-orders.retry', $order) }}" class="inline" data-confirm="Retry pushing this domain order?">
                                        @csrf
                                        <button type="submit" class="w-full sm:w-auto px-3 py-1.5 text-xs font-semibold rounded-lg text-orange-700 dark:text-orange-300 bg-orange-100 hover:bg-orange-200 dark:bg-orange-950/60 dark:hover:bg-orange-950 transition whitespace-nowrap">
                                            Retry
                                        </button>
                                    </form>
                                    @endif
                                    @if($order->canCancel())
                                    <form method="POST" action="{{ route('reseller.domain-orders.cancel', $order) }}" class="inline" data-confirm="Cancel this domain order? The pending domain registration will not proceed.">
                                        @csrf
                                        <button type="submit" class="w-full sm:w-auto px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700 dark:text-slate-200 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 transition whitespace-nowrap">
                                            Cancel
                                        </button>
                                    </form>
                                    @endif
                                    @if($order->canDelete())
                                    <form method="POST" action="{{ route('reseller.domain-orders.destroy', $order) }}" class="inline" data-confirm="Remove this domain order from your list? This cannot be undone.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="w-full sm:w-auto px-3 py-1.5 text-xs font-semibold rounded-lg text-red-700 dark:text-red-300 bg-red-50 hover:bg-red-100 dark:bg-red-950/40 dark:hover:bg-red-950/60 transition whitespace-nowrap">
                                            Delete
                                        </button>
                                    </form>
                                    @endif
                                </div>
                                @else
                                <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-600 dark:text-slate-400">No domain orders found.</td>
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
</div>
@endsection
