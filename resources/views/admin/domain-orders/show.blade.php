@extends('layouts.admin')

@section('title', 'Domain Order - ' . $order->domain_name)

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $order->domain_name }}.{{ $order->extension }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Order #{{ $order->id }}</p>
        </div>
        <span class="inline-flex items-center px-4 py-2 rounded-full text-lg font-medium {{ match($order->status) {
            'queued' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
            'pushed' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300',
            'completed' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300',
            'failed' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
            'expired' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
        } }}">
            {{ ucfirst($order->status) }}
        </span>
    </div>

    <!-- Details Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Reseller Card -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h3 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-4">Reseller</h3>
            <div class="space-y-3">
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Name</p>
                    <p class="font-semibold text-slate-900 dark:text-white">{{ $order->reseller->name }}</p>
                </div>
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Email</p>
                    <p class="font-semibold text-slate-900 dark:text-white">{{ $order->reseller->email }}</p>
                </div>
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Phone</p>
                    <p class="font-semibold text-slate-900 dark:text-white">{{ $order->reseller->phone ?? 'N/A' }}</p>
                </div>
            </div>
        </div>

        <!-- Customer Card -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h3 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-4">Customer</h3>
            <div class="space-y-3">
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Name</p>
                    <p class="font-semibold text-slate-900 dark:text-white">{{ $order->customer->name }}</p>
                </div>
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Email</p>
                    <p class="font-semibold text-slate-900 dark:text-white">{{ $order->customer->email }}</p>
                </div>
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Phone</p>
                    <p class="font-semibold text-slate-900 dark:text-white">{{ $order->customer->phone ?? 'N/A' }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Details -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Order Details</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Domain</p>
                <p class="font-semibold text-slate-900 dark:text-white text-lg">{{ $order->domain_name }}.{{ $order->extension }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Duration</p>
                <p class="font-semibold text-slate-900 dark:text-white text-lg">{{ $order->years }} Year(s)</p>
            </div>
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Wholesale Amount</p>
                <p class="font-semibold text-slate-900 dark:text-white text-lg">KES {{ number_format($order->wholesale_amount, 2) }}</p>
            </div>
        </div>

        <div class="mt-6 pt-6 border-t border-slate-200 dark:border-slate-800 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Retail Amount</p>
                <p class="font-semibold text-slate-900 dark:text-white text-lg">KES {{ number_format($order->retail_amount, 2) }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Profit Margin</p>
                <p class="font-semibold text-emerald-600 dark:text-emerald-400 text-lg">KES {{ number_format($order->retail_amount - $order->wholesale_amount, 2) }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Push Mode</p>
                <p class="font-semibold text-slate-900 dark:text-white text-lg">{{ ucfirst($order->push_mode) }}</p>
            </div>
        </div>
    </div>

    <!-- Timeline -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Timeline</h3>
        <div class="space-y-4">
            <div class="flex gap-4">
                <div class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-24">Created</div>
                <div class="text-sm text-slate-900 dark:text-white">{{ $order->created_at->format('M d, Y H:i') }}</div>
            </div>
            @if($order->queued_at)
            <div class="flex gap-4">
                <div class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-24">Queued</div>
                <div class="text-sm text-slate-900 dark:text-white">{{ $order->queued_at->format('M d, Y H:i') }}</div>
            </div>
            @endif
            @if($order->pushed_at)
            <div class="flex gap-4">
                <div class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-24">Pushed</div>
                <div class="text-sm text-slate-900 dark:text-white">{{ $order->pushed_at->format('M d, Y H:i') }}</div>
            </div>
            @endif
            @if($order->completed_at)
            <div class="flex gap-4">
                <div class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-24">Completed</div>
                <div class="text-sm text-slate-900 dark:text-white">{{ $order->completed_at->format('M d, Y H:i') }}</div>
            </div>
            @endif
            @if($order->failed_at)
            <div class="flex gap-4">
                <div class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-24">Failed</div>
                <div class="text-sm text-slate-900 dark:text-white">{{ $order->failed_at->format('M d, Y H:i') }}</div>
            </div>
            @endif
        </div>
    </div>

    <!-- Actions -->
    @if($order->status === 'pushed')
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Complete Order -->
        <form method="POST" action="{{ route('admin.domain-orders.complete', $order) }}" onsubmit="return confirm('Mark this domain as completed?');">
            @csrf
            <button type="submit" class="w-full px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg transition">
                ✓ Mark as Completed
            </button>
        </form>

        <!-- Fail Order -->
        <button type="button" onclick="document.getElementById('failForm').style.display = document.getElementById('failForm').style.display === 'none' ? 'block' : 'none'" class="w-full px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition">
            ✗ Mark as Failed
        </button>
    </div>

    <!-- Fail Form (Hidden) -->
    <div id="failForm" class="bg-white dark:bg-slate-900 rounded-2xl border border-red-200 dark:border-red-800 p-6" style="display: none;">
        <h3 class="text-lg font-semibold text-red-900 dark:text-red-200 mb-4">Mark Order as Failed</h3>
        <form method="POST" action="{{ route('admin.domain-orders.fail', $order) }}">
            @csrf
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Failure Reason</label>
                <textarea name="failure_reason" required class="w-full px-4 py-2 border border-red-300 dark:border-red-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-red-500 dark:focus:ring-red-400" rows="3" placeholder="Explain why this order is being marked as failed..."></textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition">Confirm Failure</button>
                <button type="button" onclick="document.getElementById('failForm').style.display = 'none'" class="px-6 py-2 bg-slate-300 dark:bg-slate-700 text-slate-900 dark:text-white font-medium rounded-lg transition">Cancel</button>
            </div>
        </form>
    </div>
    @endif
</div>
@endsection
