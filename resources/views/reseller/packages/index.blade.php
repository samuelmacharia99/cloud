@extends('layouts.reseller')

@section('title', 'My Package')

@section('content')
<div class="space-y-6">
    @if ($user->isResellerSuspended())
        <div class="p-4 bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 rounded-lg flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h3 class="font-semibold text-red-900 dark:text-red-100">Account suspended</h3>
                <p class="text-sm text-red-700 dark:text-red-300 mt-1">
                    Your reseller package subscription is overdue or expired. Pay your renewal invoice to restore portal and hosting access.
                </p>
            </div>
            @if ($pendingInvoice ?? null)
                <a href="{{ route('reseller.payment.select-method', $pendingInvoice) }}" class="inline-flex justify-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium transition-colors">
                    Pay renewal
                </a>
            @endif
        </div>
    @endif

    <!-- Limit Exceeded Warning Banner -->
    @if (session('limit_exceeded'))
        <div class="p-4 bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 rounded-lg">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
                <div>
                    <h3 class="font-semibold text-red-900 dark:text-red-100">Package Limits Exceeded</h3>
                    <p class="text-sm text-red-700 dark:text-red-300 mt-1">You have reached your package limits. Upgrade to continue adding services or customers.</p>
                </div>
            </div>
        </div>
    @endif

    <!-- Pending Payment Alert -->
    @if ($pendingRenewalInvoice ?? null)
        <div class="p-4 bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800 rounded-lg flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h3 class="font-semibold text-amber-900 dark:text-amber-100">Renewal payment required</h3>
                <p class="text-sm text-amber-700 dark:text-amber-300 mt-1">
                    Invoice #{{ $pendingRenewalInvoice->invoice_number }} (KSH {{ number_format($pendingRenewalInvoice->total, 0) }}) is awaiting payment
                    @if ($pendingRenewalInvoice->due_date)
                        — due {{ $pendingRenewalInvoice->due_date->format('M d, Y') }}
                    @endif
                    @if ($renewalExtendsTo ?? null)
                        . After payment, your plan extends to {{ $renewalExtendsTo->format('M d, Y') }}.
                    @endif
                </p>
            </div>
            <a href="{{ route('reseller.payment.select-method', $pendingRenewalInvoice) }}" class="inline-flex justify-center px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-sm font-medium transition-colors">
                Pay renewal
            </a>
        </div>
    @elseif ($pendingInvoice)
        <div class="p-4 bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 rounded-lg flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h3 class="font-semibold text-blue-900 dark:text-blue-100">Payment required</h3>
                <p class="text-sm text-blue-700 dark:text-blue-300 mt-1">
                    Invoice #{{ $pendingInvoice->invoice_number }} is awaiting payment before your selected plan becomes active.
                </p>
            </div>
            <a href="{{ route('reseller.payment.select-method', $pendingInvoice) }}" class="inline-flex justify-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors">
                Pay now
            </a>
        </div>
    @endif

    <!-- No Package Alert -->
    @if (!$user->hasResellerPackage())
        <div class="p-4 bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800 rounded-lg">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
                <div>
                    <h3 class="font-semibold text-amber-900 dark:text-amber-100">No Active Plan</h3>
                    <p class="text-sm text-amber-700 dark:text-amber-300 mt-1">You don't have a package yet. Select one below to start managing services and customers.</p>
                </div>
            </div>
        </div>
    @endif

    <!-- Current Plan Card -->
    @if ($user->resellerPackage)
        <div class="bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-950 dark:to-purple-900 rounded-xl border border-purple-200 dark:border-purple-800 p-8">
            <div class="max-w-3xl">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">{{ $user->resellerPackage->name }}</h2>
                <p class="text-slate-600 dark:text-slate-300 mb-6">
                    <span class="inline-block px-3 py-1 bg-purple-200 dark:bg-purple-800 text-purple-900 dark:text-purple-100 rounded-lg text-sm font-medium">
                        {{ ucfirst($user->resellerPackage->billing_cycle) }} Plan
                    </span>
                </p>

                <!-- Usage Meters -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Service Slots -->
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <span class="font-medium text-slate-900 dark:text-white">Service Slots</span>
                            <span class="text-sm text-slate-600 dark:text-slate-400">{{ $currentServices }} / {{ $user->resellerPackage->storage_space }}</span>
                        </div>
                        @php
                            $servicePct = $user->resellerPackage->storage_space > 0
                                ? min(100, round(($currentServices / $user->resellerPackage->storage_space) * 100))
                                : 0;
                            $serviceColor = $servicePct >= 90 ? 'bg-red-500' : ($servicePct >= 75 ? 'bg-amber-500' : 'bg-emerald-500');
                        @endphp
                        <div class="w-full h-3 bg-slate-300 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div class="{{ $serviceColor }} h-3 rounded-full transition-all" style="width: {{ $servicePct }}%"></div>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Active services managed by your account</p>
                    </div>

                    <!-- Hosted users -->
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <span class="font-medium text-slate-900 dark:text-white">{{ ($hostedUserCountSource ?? 'portal') === 'directadmin' ? 'Hosted users (DirectAdmin)' : 'Customers' }}</span>
                            <span class="text-sm text-slate-600 dark:text-slate-400">{{ $currentCustomers }} / {{ $user->resellerPackage->max_users }}</span>
                        </div>
                        @php
                            $customerPct = $user->resellerPackage->max_users > 0
                                ? min(100, round(($currentCustomers / $user->resellerPackage->max_users) * 100))
                                : 0;
                            $customerColor = $customerPct >= 90 ? 'bg-red-500' : ($customerPct >= 75 ? 'bg-amber-500' : 'bg-emerald-500');
                        @endphp
                        <div class="w-full h-3 bg-slate-300 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div class="{{ $customerColor }} h-3 rounded-full transition-all" style="width: {{ $customerPct }}%"></div>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            @if(($hostedUserCountSource ?? 'portal') === 'directadmin')
                                Includes all end-user accounts on your DirectAdmin reseller (platform + panel-created)
                            @else
                                Distinct customers under your reseller account
                            @endif
                        </p>
                    </div>
                </div>

                <!-- Subscription Info -->
                <div class="mt-6 pt-6 border-t border-purple-300 dark:border-purple-700">
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        <span class="font-medium">Subscribed since</span>
                        {{ $user->package_subscribed_at?->format('M d, Y') ?? 'Unknown' }}
                    </p>
                    @if ($user->package_expires_at)
                        <p class="text-sm text-slate-600 dark:text-slate-300 mt-1">
                            <span class="font-medium">Renews</span>
                            {{ $user->package_expires_at->format('M d, Y') }}
                        </p>
                    @endif
                </div>

                @php
                    $renewConfirm = $renewalTotal
                        ? ($renewalExtendsTo
                            ? 'You will be charged KSH '.number_format($renewalTotal, 0).' to renew your '.$user->resellerPackage->name.' plan. After payment, your plan extends to '.$renewalExtendsTo->format('M d, Y').'. Continue?'
                            : 'You will be charged KSH '.number_format($renewalTotal, 0).' to renew your '.$user->resellerPackage->name.' plan. Continue?')
                        : 'Renew your current plan?';
                @endphp
                <div class="mt-6 flex flex-wrap items-center gap-3">
                    @if ($pendingRenewalInvoice ?? null)
                        <a href="{{ route('reseller.payment.select-method', $pendingRenewalInvoice) }}" class="inline-flex justify-center px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition-colors">
                            Pay renewal — KSH {{ number_format($pendingRenewalInvoice->total, 0) }}
                        </a>
                    @else
                        <form action="{{ route('reseller.packages.renew') }}" method="POST" data-confirm="{{ $renewConfirm }}" data-confirm-title="Renew plan">
                            @csrf
                            <button type="submit" class="inline-flex justify-center px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition-colors">
                                Renew plan@if ($renewalTotal) — KSH {{ number_format($renewalTotal, 0) }}@endif
                            </button>
                        </form>
                    @endif
                    @if ($renewalExtendsTo && !($pendingRenewalInvoice ?? null))
                        <p class="text-sm text-slate-600 dark:text-slate-400">
                            Early renewal stacks onto your current term (extends to {{ $renewalExtendsTo->format('M d, Y') }}).
                        </p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Billing Cycle Toggle -->
    <div class="flex gap-2 justify-center">
        <a href="{{ route('reseller.packages.index', ['cycle' => 'monthly']) }}"
           class="px-6 py-2 rounded-lg font-medium transition-colors {{ $billingCycle === 'monthly' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700' }}">
            Monthly Plans
        </a>
        <a href="{{ route('reseller.packages.index', ['cycle' => 'annually']) }}"
           class="px-6 py-2 rounded-lg font-medium transition-colors {{ $billingCycle === 'annually' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700' }}">
            Annual Plans
        </a>
    </div>

    <!-- Package Cards Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse ($packages as $package)
            <div class="rounded-xl border-2 p-6 transition-all {{ $user->reseller_package_id === $package->id ? 'border-purple-500 bg-purple-50 dark:bg-purple-950' : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900' }}">
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">{{ $package->name }}</h3>
                    @if ($user->reseller_package_id === $package->id)
                        <span class="px-2.5 py-1 bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300 text-xs font-semibold rounded">Your Plan</span>
                    @elseif ($user->resellerPackage && $package->price < $user->resellerPackage->price)
                        <span class="px-2.5 py-1 bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 text-xs font-semibold rounded">Lower Tier</span>
                    @endif
                </div>

                <!-- Price -->
                <div class="mb-6 pb-6 border-b border-slate-200 dark:border-slate-700">
                    <p class="text-sm text-slate-600 dark:text-slate-400">Price</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-1">
                        KSH {{ number_format($package->price, 0) }}
                        <span class="text-lg text-slate-600 dark:text-slate-400 font-normal">/{{ $billingCycle === 'monthly' ? 'mo' : 'yr' }}</span>
                    </p>
                    @if ($quote = $upgradeQuotes[$package->id] ?? null)
                        <p class="text-base font-semibold text-purple-700 dark:text-purple-300 mt-2">
                            Upgrade today: KSH {{ number_format($quote['amount'], 0) }}
                            <span class="text-sm font-normal text-slate-600 dark:text-slate-400">(prorated, {{ $quote['days_remaining'] }} days left)</span>
                        </p>
                    @endif
                </div>

                <!-- Features -->
                <div class="space-y-3 mb-6">
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <div>
                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $package->storage_space }} Service Slots</p>
                            <p class="text-xs text-slate-600 dark:text-slate-400">Active services you can manage</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <div>
                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $package->max_users }} Customers</p>
                            <p class="text-xs text-slate-600 dark:text-slate-400">Maximum customer accounts</p>
                        </div>
                    </div>
                    @if ($package->description)
                        <p class="text-sm text-slate-600 dark:text-slate-400 pt-2">{{ $package->description }}</p>
                    @endif
                </div>

                <!-- Action Button -->
                @if ($user->reseller_package_id === $package->id)
                    @if ($pendingRenewalInvoice ?? null)
                        <a href="{{ route('reseller.payment.select-method', $pendingRenewalInvoice) }}" class="block w-full text-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition-colors">
                            Pay renewal
                        </a>
                    @else
                        <form action="{{ route('reseller.packages.renew') }}" method="POST" data-confirm="{{ $renewConfirm ?? 'Renew your current plan?' }}" data-confirm-title="Renew plan">
                            @csrf
                            <button type="submit" class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition-colors">
                                Renew plan
                            </button>
                        </form>
                    @endif
                @elseif ($user->resellerPackage && $package->price < $user->resellerPackage->price)
                    <button disabled class="w-full px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 rounded-lg text-sm font-medium cursor-not-allowed">
                        Cannot Downgrade
                    </button>
                @else
                    @php
                        $quote = $upgradeQuotes[$package->id] ?? null;
                        $chargeAmount = $quote ? $quote['amount'] : $package->price;
                        $confirmMessage = $quote
                            ? 'You will be charged KSH '.number_format($chargeAmount, 0).' (prorated upgrade for '.$quote['days_remaining'].' days remaining). Your renewal date stays the same. Continue?'
                            : 'You will be charged KSH '.number_format($chargeAmount, 0).' for this plan. Continue?';
                    @endphp
                    <form action="{{ route('reseller.packages.subscribe', $package) }}" method="POST" data-confirm="{{ $confirmMessage }}" data-confirm-title="{{ $quote ? 'Confirm upgrade' : 'Confirm subscription' }}">
                        @csrf
                        <button type="submit" class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition-colors">
                            {{ $user->resellerPackage ? 'Upgrade & pay' : 'Subscribe & pay' }}
                        </button>
                    </form>
                @endif
            </div>
        @empty
            <div class="col-span-full text-center py-12">
                <svg class="mx-auto w-12 h-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m0 0l8-4m0 0l8 4m0 0v10l-8 4m0-10L4 7m0 10v10l8 4m8-4v-10l-8-4"/>
                </svg>
                <p class="text-slate-600 dark:text-slate-400 font-medium mt-2">No {{ $billingCycle }} packages available</p>
            </div>
        @endforelse
    </div>
</div>
@endsection
