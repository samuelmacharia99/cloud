@extends('layouts.customer')

@section('title', 'Service: ' . $service->product->name)

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8" x-data="{ tab: 'overview' }">
        <div class="flex items-start justify-between mb-6">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $service->product->name }}</h1>
                <p class="text-slate-600 dark:text-slate-400 mt-2">Service #{{ $service->id }} • {{ ucfirst(str_replace('_', ' ', $service->product->type)) }}</p>

                <!-- Status badge -->
                <div class="mt-4">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if($service->status === 'active')
                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                        @elseif($service->status === 'pending')
                            bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                        @elseif($service->status === 'provisioning')
                            bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                        @elseif($service->status === 'suspended')
                            bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300
                        @elseif($service->status === 'terminated')
                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                        @elseif($service->status === 'failed')
                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                        @endif
                    ">
                        {{ ucfirst($service->status) }}
                    </span>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2 flex-wrap">
                <button class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 font-medium rounded-lg transition text-sm">
                    Open Panel
                </button>
                <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                    Request Support
                </button>
            </div>
        </div>

        <!-- Tabs -->
        <div class="border-b border-slate-200 dark:border-slate-800">
            <div class="flex gap-8 overflow-x-auto">
                <button @click="tab = 'overview'" :class="tab === 'overview' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                    Overview
                </button>
                <button @click="tab = 'billing'" :class="tab === 'billing' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                    Billing
                </button>
                <button @click="tab = 'credentials'" :class="tab === 'credentials' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                    Credentials
                </button>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="mt-6">
            <!-- Overview Tab -->
            <div x-show="tab === 'overview'" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Service Info -->
                    <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Service Information</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-slate-600 dark:text-slate-400">Service ID</span>
                                <span class="text-slate-900 dark:text-white font-medium">#{{ $service->id }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-600 dark:text-slate-400">Status</span>
                                <span class="text-slate-900 dark:text-white font-medium">{{ ucfirst($service->status) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-600 dark:text-slate-400">Created</span>
                                <span class="text-slate-900 dark:text-white font-medium">{{ $service->created_at->format('M d, Y') }}</span>
                            </div>
                            @if ($service->terminate_date)
                                <div class="flex justify-between">
                                    <span class="text-slate-600 dark:text-slate-400">Terminated</span>
                                    <span class="text-slate-900 dark:text-white font-medium">{{ $service->terminate_date->format('M d, Y') }}</span>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Billing Info -->
                    <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Billing Information</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-slate-600 dark:text-slate-400">Billing Cycle</span>
                                <span class="text-slate-900 dark:text-white font-medium">{{ ucfirst($service->billing_cycle) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-600 dark:text-slate-400">Next Due</span>
                                <span class="text-slate-900 dark:text-white font-medium">{{ $service->next_due_date?->format('M d, Y') ?? '-' }}</span>
                            </div>
                            @if ($service->invoice)
                                <div class="flex justify-between">
                                    <span class="text-slate-600 dark:text-slate-400">Invoice</span>
                                    <a href="{{ route('customer.invoices.index') }}" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium">#{{ str_pad($service->invoice->id, 5, '0', STR_PAD_LEFT) }}</a>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3" x-data="{ showCancelModal: false }">
                    @if ($service->status->value !== 'terminated' && $service->status->value !== 'cancelled')
                        <button @click="showCancelModal = true" class="px-4 py-2 bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300 hover:bg-red-200 dark:hover:bg-red-900 font-medium rounded-lg transition text-sm">
                            Cancel Service
                        </button>
                    @endif
                    @if ($service->status->value === 'active')
                        <form action="{{ route('customer.services.renew', $service) }}" method="POST" onsubmit="return confirm('Are you sure you want to renew this service? An invoice will be created.');">
                            @csrf
                            <button type="submit" class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition text-sm">
                                Renew Service
                            </button>
                        </form>
                    @endif
                    <button class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 font-medium rounded-lg transition text-sm">
                        View Documentation
                    </button>
                </div>

                <!-- Cancel Service Modal -->
                <div x-show="showCancelModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" x-cloak>
                    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-md w-full mx-4 p-6" @click.stop>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Cancel Service</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                            Are you sure you want to cancel "{{ $service->name }}"? Please provide a reason for cancellation.
                        </p>

                        <form action="{{ route('customer.services.cancel', $service) }}" method="POST">
                            @csrf
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Cancellation Reason</label>
                                <textarea
                                    name="reason"
                                    required
                                    minlength="10"
                                    maxlength="1000"
                                    class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 rounded-lg text-sm focus:ring-2 focus:ring-red-500 dark:focus:ring-red-400"
                                    rows="4"
                                    placeholder="Tell us why you want to cancel this service..."
                                ></textarea>
                                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Minimum 10 characters required</p>
                            </div>

                            <div class="flex gap-3">
                                <button type="button" @click="showCancelModal = false" class="flex-1 px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-medium rounded-lg hover:bg-slate-200 dark:hover:bg-slate-600 transition">
                                    Keep Service
                                </button>
                                <button type="submit" class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition">
                                    Cancel Service
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Billing Tab -->
            <div x-show="tab === 'billing'" class="space-y-4">
                <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Billing Summary</h3>
                    <div class="space-y-3 text-sm">
                        @if ($service->product->monthly_price)
                            <div class="flex justify-between items-center">
                                <span class="text-slate-600 dark:text-slate-400">Monthly Price</span>
                                <span class="text-slate-900 dark:text-white font-medium">${{ number_format($service->product->monthly_price, 2) }}</span>
                            </div>
                        @endif
                        @if ($service->product->yearly_price)
                            <div class="flex justify-between items-center">
                                <span class="text-slate-600 dark:text-slate-400">Yearly Price</span>
                                <span class="text-slate-900 dark:text-white font-medium">${{ number_format($service->product->yearly_price, 2) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between items-center pt-3 border-t border-slate-200 dark:border-slate-700">
                            <span class="text-slate-900 dark:text-white font-semibold">Billing Cycle</span>
                            <span class="text-slate-900 dark:text-white font-semibold">{{ ucfirst($service->billing_cycle) }}</span>
                        </div>
                    </div>
                </div>

                @if ($service->invoice)
                    <div class="bg-blue-50 dark:bg-blue-950 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                        <p class="text-sm text-blue-900 dark:text-blue-100">
                            <strong>Invoice {{ str_pad($service->invoice->id, 5, '0', STR_PAD_LEFT) }}:</strong> ${{ number_format($service->invoice->total, 2) }} • Status: {{ ucfirst($service->invoice->status) }}
                        </p>
                        <a href="{{ route('customer.invoices.index') }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 mt-2 inline-block">
                            View Invoice →
                        </a>
                    </div>
                @endif
            </div>

            <!-- Credentials Tab -->
            <div x-show="tab === 'credentials'" class="space-y-4">
                <div class="bg-amber-50 dark:bg-amber-950 rounded-lg p-4 border border-amber-200 dark:border-amber-800">
                    <p class="text-sm text-amber-900 dark:text-amber-100">
                        Service credentials and access information will be displayed here once your service is provisioned.
                    </p>
                </div>

                @if ($service->status === 'active' && $service->credentials)
                    <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Access Information</h3>
                        <div class="bg-white dark:bg-slate-900 rounded border border-slate-200 dark:border-slate-700 p-3">
                            <p class="text-xs font-mono text-slate-600 dark:text-slate-400 break-all">{{ $service->credentials }}</p>
                        </div>
                        <button class="mt-2 text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">
                            Copy Credentials
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
