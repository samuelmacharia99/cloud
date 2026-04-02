@extends('layouts.admin')

@section('title', $order->order_number)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.orders.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Orders</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">{{ $order->order_number }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $order->order_number }}</h1>
                <p class="text-slate-600 dark:text-slate-400 mt-2">{{ $order->user->name }} • {{ $order->user->email }}</p>

                <!-- Status badges -->
                <div class="mt-4 flex items-center gap-2">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if($order->status === 'pending')
                            bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                        @elseif($order->status === 'paid')
                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                        @elseif($order->status === 'cancelled')
                            bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                        @elseif($order->status === 'failed')
                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                        @else
                            bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                        @endif
                    ">
                        {{ ucfirst($order->status) }}
                    </span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if($order->payment_status === 'paid')
                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                        @elseif($order->payment_status === 'unpaid')
                            bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                        @elseif($order->payment_status === 'refunded')
                            bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300
                        @else
                            bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                        @endif
                    ">
                        {{ ucfirst($order->payment_status) }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Order Items -->
            @if ($order->items->count() > 0)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Order Items</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-slate-200 dark:border-slate-700">
                                <tr>
                                    <th class="text-left py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Product</th>
                                    <th class="text-right py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Qty</th>
                                    <th class="text-right py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Unit Price</th>
                                    <th class="text-left py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Billing</th>
                                    <th class="text-right py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                @foreach ($order->items as $item)
                                    <tr>
                                        <td class="py-3 px-3">
                                            <div>
                                                <p class="font-medium text-slate-900 dark:text-white">{{ $item->product->name ?? 'Unknown Product' }}</p>
                                                <p class="text-xs text-slate-600 dark:text-slate-400">{{ $item->description }}</p>
                                            </div>
                                        </td>
                                        <td class="py-3 px-3 text-right text-slate-900 dark:text-white">{{ $item->quantity }}</td>
                                        <td class="py-3 px-3 text-right text-slate-900 dark:text-white">${{ number_format($item->unit_price, 2) }}</td>
                                        <td class="py-3 px-3 text-slate-600 dark:text-slate-400 text-xs">{{ $item->billing_cycle ? ucfirst($item->billing_cycle) : '-' }}</td>
                                        <td class="py-3 px-3 text-right font-medium text-slate-900 dark:text-white">${{ number_format($item->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Totals -->
                    <div class="mt-4 border-t border-slate-200 dark:border-slate-700 pt-4">
                        <div class="flex justify-end gap-16">
                            <div>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Subtotal</p>
                                <p class="font-medium text-slate-900 dark:text-white">${{ number_format($order->subtotal, 2) }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Tax</p>
                                <p class="font-medium text-slate-900 dark:text-white">${{ number_format($order->tax, 2) }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Total</p>
                                <p class="font-bold text-lg text-slate-900 dark:text-white">${{ number_format($order->total, 2) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Notes -->
            @if ($order->notes)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Notes</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400">{{ $order->notes }}</p>
                </div>
            @endif
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Customer Info -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Customer</h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold">
                            {{ strtoupper(substr($order->user->name, 0, 1)) }}
                        </div>
                        <div>
                            <p class="font-medium text-slate-900 dark:text-white">{{ $order->user->name }}</p>
                            <p class="text-xs text-slate-600 dark:text-slate-400">{{ $order->user->email }}</p>
                        </div>
                    </div>
                    <a href="{{ route('admin.customers.show', $order->user) }}" class="block mt-4 px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                        View Customer
                    </a>
                </div>
            </div>

            <!-- Invoice Placeholder -->
            <div class="bg-slate-50 dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Invoice</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400">Invoice will be generated once payment is confirmed.</p>
            </div>

            <!-- Related Services Placeholder -->
            <div class="bg-slate-50 dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Related Services</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400">Service provisioning will begin after payment.</p>
            </div>

            <!-- Timeline -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Timeline</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-slate-900 dark:text-white">{{ $order->created_at->format('M d, Y \a\t h:i A') }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Last Updated</p>
                        <p class="text-slate-900 dark:text-white">{{ $order->updated_at->format('M d, Y \a\t h:i A') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
