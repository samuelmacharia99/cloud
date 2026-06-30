@extends('layouts.reseller')

@section('title', 'Reseller Dashboard')

@section('content')
@php
    $customerSubtitle = ($portalCustomerCount ?? 0).' portal';
    if (($hostedUserCountSource ?? 'portal') === 'directadmin') {
        $customerSubtitle .= ' · '.($customerCount ?? 0).' on DirectAdmin';
    }
    if (($unlinkedDaCount ?? 0) > 0) {
        $customerSubtitle .= ' · '.($unlinkedDaCount).' unlinked';
    }
    $maxUsers = $resellerPackage->max_users ?? 0;
    $hasServerPulse = ($directAdminMonitor['connected'] ?? false) || ($hasDirectAdmin ?? false);
    $defaultDashboardTab = $hasServerPulse ? 'server' : 'activity';
@endphp

<div class="space-y-6" x-data="{ dashboardTab: @js($defaultDashboardTab) }">
    <x-reseller-page-header
        title="Dashboard"
        description="{{ $billingHealth['message'] ?? 'Manage customers, retail billing, and your whitelabel business.' }}"
    />

    <x-reseller-status-strip
        :billing-health="$billingHealth"
        :wallet-balance="$walletBalance ?? null"
        :wallet-is-low="$walletIsLow ?? false"
        :wallet-currency="$walletCurrency ?? 'KSH'"
        :package-expires-at="$packageExpiresAt"
        :days-until-package-expiry="$daysUntilPackageExpiry"
        :has-direct-admin="$hasDirectAdmin ?? false"
        :unlinked-da-count="$unlinkedDaCount ?? 0"
        :active-services="$activeServices"
        :max-services="$maxServices"
        :customer-count="$customerCount"
        :max-users="$maxUsers"
        :disk-pool-percent="$diskPoolPercent"
    />

    <x-reseller-onboarding-checklist :onboarding="$onboarding ?? []" :has-direct-admin="$hasDirectAdmin ?? false" />

    <x-reseller-action-queue :queue="$actionQueue ?? []" />

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <a href="{{ route('reseller.customers.index') }}" class="block bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 hover:border-purple-300 dark:hover:border-purple-700 transition shadow-sm">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Customers</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-1">{{ $customerCount }}</p>
            <p class="text-xs text-slate-500 mt-2">{{ $customerSubtitle }}</p>
        </a>
        <a href="{{ route('reseller.services.index') }}" class="block bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 hover:border-emerald-300 dark:hover:border-emerald-700 transition shadow-sm">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Active services</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-1">{{ $activeServices }}</p>
            <p class="text-xs text-slate-500 mt-2">{{ $suspendedServices }} suspended · view all</p>
        </a>
        <a href="{{ route('reseller.customer-invoices.index', ['status' => 'unpaid']) }}" class="block bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 hover:border-amber-300 dark:hover:border-amber-700 transition shadow-sm">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Outstanding</p>
            <p class="text-3xl font-bold text-amber-600 mt-1">KSH {{ number_format($outstandingBalance, 0) }}</p>
            <p class="text-xs text-slate-500 mt-2">{{ ($invoiceStatus['unpaid'] ?? 0) + ($invoiceStatus['overdue'] ?? 0) }} open invoice(s)</p>
        </a>
        <a href="{{ route('reseller.customer-payments.index') }}" class="block bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 hover:border-emerald-300 dark:hover:border-emerald-700 transition shadow-sm">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Collected (30d)</p>
            <p class="text-3xl font-bold text-emerald-600 mt-1">KSH {{ number_format($revenue30d ?? 0, 0) }}</p>
            <p class="text-xs text-slate-500 mt-2">KSH {{ number_format($totalRevenue, 0) }} all-time paid</p>
        </a>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="flex border-b border-slate-200 dark:border-slate-800 overflow-x-auto">
            @if ($hasServerPulse)
                <button type="button" @click="dashboardTab = 'server'" :class="dashboardTab === 'server' ? 'border-b-2 border-purple-600 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400'" class="px-5 py-3 text-sm font-medium whitespace-nowrap">Server pulse</button>
            @endif
            <button type="button" @click="dashboardTab = 'activity'" :class="dashboardTab === 'activity' ? 'border-b-2 border-purple-600 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400'" class="px-5 py-3 text-sm font-medium whitespace-nowrap">Recent activity</button>
            <button type="button" @click="dashboardTab = 'revenue'" :class="dashboardTab === 'revenue' ? 'border-b-2 border-purple-600 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400'" class="px-5 py-3 text-sm font-medium whitespace-nowrap">Revenue</button>
        </div>

        <div class="p-5 sm:p-6">
            @if ($hasServerPulse)
                <div x-show="dashboardTab === 'server'" x-cloak>
                    @include('reseller.dashboard.partials.directadmin-monitor', ['directAdminMonitor' => $directAdminMonitor ?? []])
                </div>
            @endif

            <div
                x-show="dashboardTab === 'activity'"
                x-cloak
                x-data="resellerActivityFeed(@js([
                    'initial' => $activityFeed ?? [],
                    'hasMore' => $activityFeedHasMore ?? false,
                    'nextOffset' => $activityFeedNextOffset ?? 10,
                    'loadUrl' => route('reseller.dashboard.activity'),
                ]))"
            >
                <template x-if="items.length === 0">
                    <p class="text-sm text-slate-500 text-center py-8">No recent activity yet. Create a customer or invoice to get started.</p>
                </template>
                <template x-for="(item, index) in items" :key="item.at + '-' + item.url + '-' + index">
                    <a :href="item.url" class="flex items-center justify-between gap-4 py-3 border-b border-slate-100 dark:border-slate-800 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-800/50 px-2 -mx-2 rounded-lg transition">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900 dark:text-white truncate" x-text="item.title"></p>
                            <p class="text-xs text-slate-500 truncate" x-show="item.subtitle" x-text="item.subtitle"></p>
                        </div>
                        <span class="text-[10px] uppercase tracking-wide text-slate-400 shrink-0" x-text="item.type.replace(/_/g, ' ')"></span>
                    </a>
                </template>
                <div class="flex justify-center mt-4" x-show="hasMore">
                    <button
                        type="button"
                        @click="loadMore()"
                        :disabled="loading"
                        class="px-4 py-2 text-sm font-medium text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800 rounded-lg hover:bg-purple-50 dark:hover:bg-purple-950/30 disabled:opacity-50"
                    >
                        <span x-show="!loading">Next</span>
                        <span x-show="loading">Loading…</span>
                    </button>
                </div>
                <div class="flex flex-wrap gap-3 mt-4 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <a href="{{ route('reseller.customer-invoices.create') }}" class="text-xs font-medium text-purple-600">New invoice</a>
                    <a href="{{ route('reseller.domains.index') }}" class="text-xs font-medium text-purple-600">Domains</a>
                    <a href="{{ route('reseller.customers.create') }}" class="text-xs font-medium text-purple-600">Add customer</a>
                </div>
            </div>

            <div x-show="dashboardTab === 'revenue'" x-cloak>
                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800">
                        <p class="text-xs text-emerald-700 dark:text-emerald-300">Paid</p>
                        <p class="text-2xl font-bold text-emerald-700 dark:text-emerald-300">{{ $invoiceStatus['paid'] ?? 0 }}</p>
                    </div>
                    <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800">
                        <p class="text-xs text-amber-700 dark:text-amber-300">Unpaid</p>
                        <p class="text-2xl font-bold text-amber-700 dark:text-amber-300">{{ $invoiceStatus['unpaid'] ?? 0 }}</p>
                    </div>
                    <div class="p-4 rounded-xl bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800">
                        <p class="text-xs text-red-700 dark:text-red-300">Overdue</p>
                        <p class="text-2xl font-bold text-red-700 dark:text-red-300">{{ $invoiceStatus['overdue'] ?? 0 }}</p>
                    </div>
                </div>
                @if (! empty($monthlyRevenue))
                    <p class="text-xs font-medium text-slate-500 mb-3">Customer payments received (6 months)</p>
                    <div class="flex items-end gap-2 h-32">
                        @foreach ($monthlyRevenue as $index => $amount)
                            @php $max = max($monthlyRevenue) ?: 1; $height = max(4, ($amount / $max) * 100); @endphp
                            <div class="flex-1 flex flex-col items-center gap-1">
                                <div class="w-full bg-purple-500 rounded-t" style="height: {{ $height }}%"></div>
                                <span class="text-[10px] text-slate-500">{{ now()->subMonths(5 - $index)->format('M') }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
                <p class="text-xs text-slate-500 mt-4">
                    <a href="{{ route('reseller.reports.index') }}" class="text-purple-600 font-medium">Full reports & margins →</a>
                </p>
            </div>
        </div>
    </div>
</div>

<script>
    function resellerActivityFeed(config) {
        return {
            items: config.initial || [],
            hasMore: config.hasMore || false,
            nextOffset: config.nextOffset || 10,
            loading: false,
            loadUrl: config.loadUrl,
            async loadMore() {
                if (this.loading || ! this.hasMore) {
                    return;
                }

                this.loading = true;

                try {
                    const response = await fetch(`${this.loadUrl}?offset=${this.nextOffset}`, {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (! response.ok) {
                        throw new Error('Unable to load more activity.');
                    }

                    const data = await response.json();
                    this.items = [...this.items, ...(data.items || [])];
                    this.hasMore = Boolean(data.has_more);
                    this.nextOffset = data.next_offset ?? this.nextOffset;
                } catch (error) {
                    console.error(error);
                } finally {
                    this.loading = false;
                }
            },
        };
    }
</script>
@endsection
