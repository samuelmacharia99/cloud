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
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800">
        <div class="overflow-x-auto overflow-y-visible">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Domain</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Customer</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Wholesale</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Created</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($orders as $order)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $order->domain_name }}.{{ $order->extension }}</p>
                                <p class="text-xs text-slate-600 dark:text-slate-400">{{ $order->years }} year(s)</p>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-900 dark:text-white">{{ $order->customer->name }}</td>
                            <td class="px-6 py-4">
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
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Expires: {{ $order->expires_at->format('M d') }}</p>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right text-sm font-medium text-slate-900 dark:text-white">KSH {{ number_format($order->wholesale_amount, 2) }}</td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $order->created_at->format('M d, Y') }}</td>
                            <td class="px-6 py-4 text-right overflow-visible">
                                @if($order->status === 'queued' || ($order->status === 'failed' && $order->canRetry()) || $order->canCancel() || $order->canDelete())
                                <div x-data="{ open: false }" class="relative inline-block text-left z-10">
                                    <button type="button" @click="open = !open" class="action-icon-btn text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Order actions">
                                        <svg fill="currentColor" viewBox="0 0 20 20" aria-hidden="true" class="w-5 h-5">
                                            <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/>
                                        </svg>
                                    </button>
                                    <div x-show="open" x-cloak @click.outside="open = false"
                                        class="absolute right-0 mt-1 w-52 bg-white dark:bg-slate-900 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 z-50 overflow-hidden">
                                        @if($order->status === 'queued')
                                        <form method="POST" action="{{ route('reseller.domain-orders.push', $order) }}"
                                            data-confirm="Push this domain order to admin now?"
                                            @submit="open = false">
                                            @csrf
                                            <button type="submit" class="w-full text-left px-4 py-3 hover:bg-blue-50 dark:hover:bg-blue-950/40 transition text-sm font-medium text-blue-600 dark:text-blue-400 border-b border-slate-100 dark:border-slate-800">
                                                Push to admin
                                            </button>
                                        </form>
                                        @elseif($order->status === 'failed' && $order->canRetry())
                                        <form method="POST" action="{{ route('reseller.domain-orders.retry', $order) }}"
                                            data-confirm="Retry pushing this domain order?"
                                            @submit="open = false">
                                            @csrf
                                            <button type="submit" class="w-full text-left px-4 py-3 hover:bg-orange-50 dark:hover:bg-orange-950/40 transition text-sm font-medium text-orange-600 dark:text-orange-400 border-b border-slate-100 dark:border-slate-800">
                                                Retry push
                                            </button>
                                        </form>
                                        @endif
                                        @if($order->canCancel())
                                        <form method="POST" action="{{ route('reseller.domain-orders.cancel', $order) }}"
                                            data-confirm="Cancel this domain order? The pending domain registration will not proceed."
                                            @submit="open = false">
                                            @csrf
                                            <button type="submit" class="w-full text-left px-4 py-3 hover:bg-orange-50 dark:hover:bg-orange-950/40 transition text-sm font-medium text-orange-600 dark:text-orange-400 {{ $order->canDelete() ? 'border-b border-slate-100 dark:border-slate-800' : '' }}">
                                                Cancel order
                                            </button>
                                        </form>
                                        @endif
                                        @if($order->canDelete())
                                        <form method="POST" action="{{ route('reseller.domain-orders.destroy', $order) }}"
                                            data-confirm="Remove this domain order from your list? This cannot be undone."
                                            @submit="open = false">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="w-full text-left px-4 py-3 hover:bg-red-50 dark:hover:bg-red-950/40 transition flex items-center gap-3 text-sm font-medium text-red-600 dark:text-red-400">
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                                Delete order
                                            </button>
                                        </form>
                                        @endif
                                    </div>
                                </div>
                                @else
                                <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">No domain orders found.</td>
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
