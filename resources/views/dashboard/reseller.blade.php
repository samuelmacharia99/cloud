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
    $health = $hostingHealth ?? ['failed_services' => 0, 'suspended_services' => 0, 'containers_down' => 0, 'total_services' => 0];
    $applicationCount = (int) ($platformHosting['container_count'] ?? 0);
    // A reseller selling only application hosting used to get no infrastructure
    // view at all, because this panel was gated on a DirectAdmin binding.
    $hasServerPulse = ($directAdminMonitor['connected'] ?? false) || ($hasDirectAdmin ?? false) || $applicationCount > 0;
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
        :compute-pool="$computePool ?? null"
        :disk-pool-percent="$diskPoolPercent"
        :disk-pool-gb="$diskPoolGb ?? 0"
        :disk-used-gb="$diskUsedGb ?? 0"
        :disk-remaining-gb="$diskRemainingGb ?? 0"
        :disk-direct-admin-gb="$diskDirectAdminGb ?? 0"
        :disk-container-gb="$diskContainerGb ?? 0"
    />

    <x-reseller-onboarding-checklist :onboarding="$onboarding ?? []" :has-direct-admin="$hasDirectAdmin ?? false" />

    <x-reseller-action-queue :queue="$actionQueue ?? []" />

    @php $platform = $platformHosting ?? ['snapshot_count' => 0, 'dns_record_count' => 0, 'container_count' => 0, 'recent' => collect()]; @endphp
    @if (($platform['snapshot_count'] ?? 0) > 0 || ($platform['container_count'] ?? 0) > 0)
        <div class="ui-card p-6 space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Hosting on Talksasa</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                    DirectAdmin zones and mailboxes are stored here. Customers manage sites from their portal — you do not need the DirectAdmin panel for captured accounts.
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                <div class="rounded-lg border border-slate-200 dark:border-slate-700 p-3">
                    <p class="text-slate-500">Captured accounts</p>
                    <p class="text-2xl font-bold">{{ $platform['snapshot_count'] }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 dark:border-slate-700 p-3">
                    <p class="text-slate-500">DNS records stored</p>
                    <p class="text-2xl font-bold">{{ $platform['dns_record_count'] }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 dark:border-slate-700 p-3">
                    <p class="text-slate-500">Application Hosting</p>
                    <p class="text-2xl font-bold">{{ $platform['container_count'] }}</p>
                    @if (($diskPoolGb ?? 0) > 0)
                        <p class="text-xs text-slate-500 mt-1">{{ number_format((float) ($diskContainerGb ?? 0), 1) }} GB of {{ number_format((int) $diskPoolGb) }} GB pool</p>
                    @endif
                </div>
            </div>
            @if (($platform['recent'] ?? collect())->isNotEmpty())
                <ul class="divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                    @foreach ($platform['recent'] as $snap)
                        <li class="py-2 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono truncate">{{ $snap->primary_domain ?: ($snap->service?->name ?? 'Account') }}</p>
                                <p class="text-xs text-slate-500">{{ $snap->dns_record_count }} DNS · {{ $snap->mailbox_count }} mail · {{ $snap->database_count }} DB</p>
                            </div>
                            @if ($snap->service_id)
                                <a href="{{ route('reseller.services.show', $snap->service_id) }}" class="text-purple-600 shrink-0">Open</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <a href="{{ route('reseller.customers.index') }}" class="block ui-card p-5 hover:border-purple-300 dark:hover:border-purple-700 transition shadow-sm">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Customers</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-1">{{ $customerCount }}</p>
            <p class="text-xs text-slate-500 mt-2">{{ $customerSubtitle }}</p>
        </a>
        <a href="{{ route('reseller.services.index') }}" class="block ui-card p-5 hover:border-emerald-300 dark:hover:border-emerald-700 transition shadow-sm">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Service slots</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-1">{{ $activeServices }}{{ $maxServices ? ' / '.$maxServices : '' }}</p>
            <p class="text-xs text-slate-500 mt-2">
                @if (($serviceSlotSource ?? 'portal') === 'directadmin')
                    DirectAdmin users · {{ $suspendedServices }} portal suspended
                @else
                    {{ $suspendedServices }} suspended · view all
                @endif
            </p>
        </a>
        <a href="{{ route('reseller.customer-invoices.index', ['status' => 'unpaid']) }}" class="block ui-card p-5 hover:border-amber-300 dark:hover:border-amber-700 transition shadow-sm">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Outstanding</p>
            <p class="text-3xl font-bold text-amber-600 mt-1">KSH {{ number_format($outstandingBalance, 0) }}</p>
            <p class="text-xs text-slate-500 mt-2">{{ ($invoiceStatus['unpaid'] ?? 0) + ($invoiceStatus['overdue'] ?? 0) }} open invoice(s)</p>
        </a>
        <a href="{{ route('reseller.customer-payments.index') }}" class="block ui-card p-5 hover:border-emerald-300 dark:hover:border-emerald-700 transition shadow-sm">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Collected (30d)</p>
            <p class="text-3xl font-bold text-emerald-600 mt-1">KSH {{ number_format($revenue30d ?? 0, 0) }}</p>
            <p class="text-xs text-slate-500 mt-2">KSH {{ number_format($totalRevenue, 0) }} all-time paid</p>
        </a>
    </div>

    <div class="ui-card overflow-hidden">
        <div class="flex border-b border-slate-200 dark:border-slate-800 overflow-x-auto">
            @if ($hasServerPulse)
                <button type="button" @click="dashboardTab = 'server'" :class="dashboardTab === 'server' ? 'border-b-2 border-purple-600 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400'" class="px-5 py-3 text-sm font-medium whitespace-nowrap">Server pulse</button>
            @endif
            <button type="button" @click="dashboardTab = 'activity'" :class="dashboardTab === 'activity' ? 'border-b-2 border-purple-600 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400'" class="px-5 py-3 text-sm font-medium whitespace-nowrap">Recent activity</button>
            <button type="button" @click="dashboardTab = 'revenue'" :class="dashboardTab === 'revenue' ? 'border-b-2 border-purple-600 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400'" class="px-5 py-3 text-sm font-medium whitespace-nowrap">Revenue</button>
        </div>

        <div class="p-5 sm:p-6">
            @if ($hasServerPulse)
                <div x-show="dashboardTab === 'server'" x-cloak class="space-y-6">
                    @if (($directAdminMonitor['connected'] ?? false) || ($hasDirectAdmin ?? false))
                        @include('reseller.dashboard.partials.directadmin-monitor', ['directAdminMonitor' => $directAdminMonitor ?? []])
                    @endif

                    @if ($applicationCount > 0)
                        @include('reseller.dashboard.partials.application-hosting-pulse', [
                            'applicationCount' => $applicationCount,
                            'health' => $health,
                            'containerDiskGb' => $diskContainerGb ?? 0,
                            'diskPoolGb' => $diskPoolGb ?? 0,
                        ])
                    @endif
                </div>
            @endif

            <div
                x-show="dashboardTab === 'activity'"
                x-cloak
                x-data="resellerActivityFeed(@js([
                    'initial' => $activityFeed ?? [],
                    'hasMore' => $activityFeedHasMore ?? false,
                    'nextOffset' => $activityFeedNextOffset ?? 0,
                    'loadUrl' => route('reseller.dashboard.activity'),
                    'lazy' => $activityFeedLazy ?? false,
                ]))"
                x-init="init()"
            >
                <template x-if="initialLoading">
                    <p class="text-sm text-slate-500 text-center py-8">Loading recent activity…</p>
                </template>
                <template x-if="!initialLoading && items.length === 0">
                    <p class="text-sm text-slate-500 text-center py-8">No recent activity yet. Create a customer or invoice to get started.</p>
                </template>
                <template x-for="(item, index) in items" :key="item.at + '-' + item.url + '-' + index">
                    <div class="flex items-center justify-between gap-4 py-3 border-b border-slate-100 dark:border-slate-800 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-800/50 px-2 -mx-2 rounded-lg transition">
                        <div class="min-w-0">
                            <a :href="item.url" class="text-sm font-medium text-slate-900 dark:text-white truncate block hover:underline" x-text="item.title"></a>
                            <div class="flex flex-wrap items-center gap-x-1 text-xs text-slate-500 min-w-0">
                                <template x-if="item.customer_url">
                                    <a :href="item.customer_url" class="font-medium text-purple-700 dark:text-purple-300 hover:underline truncate" x-text="item.customer_name"></a>
                                </template>
                                <template x-if="!item.customer_url && item.customer_name">
                                    <span class="truncate" x-text="item.customer_name"></span>
                                </template>
                                <span x-show="item.subtitle" class="truncate" x-text="(item.customer_name ? ' · ' : '') + item.subtitle"></span>
                            </div>
                        </div>
                        <span class="text-[10px] uppercase tracking-wide text-slate-400 shrink-0" x-text="item.type.replace(/_/g, ' ')"></span>
                    </div>
                </template>
                <div class="flex justify-center mt-4" x-show="hasMore && !initialLoading">
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
            nextOffset: config.nextOffset || 0,
            loading: false,
            initialLoading: Boolean(config.lazy) && (config.initial || []).length === 0,
            loadUrl: config.loadUrl,
            init() {
                if (config.lazy && this.items.length === 0) {
                    this.loadMore();
                }
            },
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
                    this.initialLoading = false;
                }
            },
        };
    }
</script>
@endsection
