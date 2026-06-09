@extends('layouts.reseller')

@section('title', 'Reseller Dashboard')

@section('content')
@php
    $health = $billingHealth ?? [];
    $severity = $health['severity'] ?? 'success';
    $badgeClasses = match ($severity) {
        'danger' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
        'warning' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
        default => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300',
    };
    $dotClasses = match ($severity) {
        'danger' => 'bg-red-600',
        'warning' => 'bg-amber-600',
        default => 'bg-emerald-600',
    };
    $statusLabel = match ($health['status'] ?? 'active') {
        'suspended' => 'Account Suspended',
        'billing_due' => 'Subscription Due',
        'expired' => 'Package Expired',
        'expiring_soon' => 'Renewal Soon',
        'no_package' => 'No Package',
        default => 'Whitelabel active',
    };
@endphp
<div class="space-y-8">
    <div class="bg-gradient-to-r from-purple-600 to-purple-700 dark:from-purple-900 dark:to-purple-800 rounded-xl border border-purple-500 dark:border-purple-700 p-8 text-white">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
            <div>
                <h1 class="text-3xl font-bold">Whitelabel dashboard</h1>
                <p class="text-purple-100 mt-2">{{ $health['message'] ?? 'Manage your branded customers, retail billing, and margins.' }}</p>
                @if (!empty($health['pending_own_invoice_url']))
                    <a href="{{ $health['pending_own_invoice_url'] }}" class="inline-flex items-center gap-2 mt-4 px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg text-sm font-medium">
                        Pay subscription invoice →
                    </a>
                @endif
                <div class="flex flex-wrap gap-3 mt-4">
                    <a href="{{ route('reseller.customers.index') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg text-sm font-medium">Customers</a>
                    <a href="{{ route('reseller.customer-invoices.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg text-sm font-medium">New invoice</a>
                    <a href="{{ route('reseller.customer-orders.hosting.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg text-sm font-medium">Order hosting</a>
                    <a href="{{ route('reseller.customer-orders.domain.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg text-sm font-medium">Register domain</a>
                </div>
            </div>
            <div class="flex flex-col items-end gap-3">
                <div class="flex items-center gap-2 px-4 py-2 rounded-full {{ $badgeClasses }}">
                    <div class="w-2 h-2 rounded-full {{ $dotClasses }}"></div>
                    <span class="text-sm font-medium">{{ $statusLabel }}</span>
                </div>
                @isset($walletBalance)
                    <a href="{{ route('reseller.wallet.index') }}" class="text-right block px-4 py-3 rounded-xl bg-white/10 hover:bg-white/20 transition">
                        <p class="text-xs text-purple-200">Wallet balance</p>
                        <p class="text-xl font-bold">{{ $walletCurrency ?? 'KSH' }} {{ number_format($walletBalance, 2) }}</p>
                        @if ($walletIsLow ?? false)
                            <p class="text-xs text-amber-200 mt-1">Low balance — top up soon</p>
                        @endif
                    </a>
                @endisset
            </div>
        </div>
    </div>

    @if (!empty($actionQueue))
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Action queue</h2>
            <ul class="space-y-2">
                @foreach ($actionQueue as $item)
                    <li>
                        <a href="{{ $item['url'] }}" class="flex items-center justify-between p-3 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition
                            @if(($item['severity'] ?? '') === 'danger') border-l-4 border-red-500 @elseif(($item['severity'] ?? '') === 'warning') border-l-4 border-amber-500 @else border-l-4 border-purple-500 @endif">
                            <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $item['label'] }}</span>
                            <span class="text-xs text-purple-600">View →</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Active Services</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $activeServices }}</p>
            <p class="text-xs text-slate-500 mt-4">{{ $suspendedServices }} suspended</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Customers</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $customerCount }}</p>
            <a href="{{ route('reseller.customers.create') }}" class="text-xs text-purple-600 mt-4 inline-block">Add customer →</a>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Collected revenue</p>
            <p class="text-3xl font-bold text-emerald-600 mt-2">KSH {{ number_format($totalRevenue, 2) }}</p>
            <p class="text-xs text-slate-500 mt-4">Paid customer invoices</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Outstanding</p>
            <p class="text-3xl font-bold text-amber-600 mt-2">KSH {{ number_format($outstandingBalance, 2) }}</p>
            <p class="text-xs text-slate-500 mt-4">Remaining on unpaid invoices</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Managed Services</h2>
                    <a href="{{ route('reseller.services.index') }}" class="text-sm text-purple-600">View all</a>
                </div>
                @forelse ($managedServices as $service)
                    <a href="{{ route('reseller.services.show', $service) }}" class="block p-4 border-b border-slate-100 dark:border-slate-800 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-800">
                        <div class="flex justify-between">
                            <div>
                                <p class="font-medium text-slate-900 dark:text-white">{{ $service->name ?? $service->product?->name }}</p>
                                <p class="text-xs text-slate-500">{{ $service->user?->name }}</p>
                            </div>
                            <x-status-badge :status="$service->status" type="service" />
                        </div>
                    </a>
                @empty
                    <p class="p-8 text-center text-slate-500">No managed services yet.</p>
                @endforelse
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Invoices</h2>
                    <a href="{{ route('reseller.customer-invoices.index') }}" class="text-sm text-purple-600">Billing →</a>
                </div>
                @forelse ($recentInvoices as $invoice)
                    <a href="{{ route('reseller.customer-invoices.show', $invoice) }}" class="block p-4 border-b border-slate-100 dark:border-slate-800 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-800 flex justify-between">
                        <div>
                            <p class="font-medium">{{ $invoice->invoice_number }}</p>
                            <p class="text-xs text-slate-500">{{ $invoice->user?->name }}</p>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold">KSH {{ number_format($invoice->total, 2) }}</p>
                            <x-status-badge :status="$invoice->status" type="invoice" />
                        </div>
                    </a>
                @empty
                    <p class="p-8 text-center text-slate-500">No invoices yet.</p>
                @endforelse
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-950 dark:to-purple-900 rounded-xl border border-purple-200 dark:border-purple-800 p-6">
                <div class="flex justify-between mb-4">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Your Plan</h3>
                    <a href="{{ route('reseller.packages.index') }}" class="text-xs text-purple-600 font-medium">Manage</a>
                </div>
                @if ($resellerPackage)
                    <p class="text-lg font-bold text-purple-700 dark:text-purple-300">{{ $resellerPackage->name }}</p>
                    @if ($packageExpiresAt)
                        <p class="text-xs text-slate-500 mt-1">Expires {{ $packageExpiresAt->format('M d, Y') }}
                            @if ($daysUntilPackageExpiry !== null)
                                ({{ $daysUntilPackageExpiry >= 0 ? $daysUntilPackageExpiry.' days left' : abs($daysUntilPackageExpiry).' days overdue' }})
                            @endif
                        </p>
                    @endif
                    <div class="mt-4">
                        <div class="flex justify-between text-xs text-slate-600 mb-1">
                            <span>Service slots</span>
                            <span>{{ $activeServices }} / {{ $maxServices }}</span>
                        </div>
                        @php $servicePct = $maxServices > 0 ? min(100, round(($activeServices / $maxServices) * 100)) : 0; @endphp
                        <div class="w-full h-2 bg-slate-300 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div class="h-2 rounded-full bg-purple-500" style="width: {{ $servicePct }}%"></div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="flex justify-between text-xs text-slate-600 mb-1">
                            <span>Customers</span>
                            <span>{{ $customerCount }} / {{ $resellerPackage->max_users }}</span>
                        </div>
                    </div>
                    @if (($diskPoolGb ?? 0) > 0)
                        <div class="mt-4">
                            <div class="flex justify-between text-xs text-slate-600 mb-1">
                                <span>Disk pool</span>
                                <span>{{ number_format($diskUsedGb ?? 0, 1) }} / {{ $diskPoolGb }} GB</span>
                            </div>
                            @php $diskPct = min(100, (float) ($diskPoolPercent ?? 0)); @endphp
                            <div class="w-full h-2 bg-slate-300 dark:bg-slate-700 rounded-full overflow-hidden">
                                <div class="h-2 rounded-full {{ $diskPct >= 90 ? 'bg-amber-500' : 'bg-emerald-500' }}" style="width: {{ $diskPct }}%"></div>
                            </div>
                            <p class="text-[11px] text-slate-500 mt-1">DA {{ number_format($diskDirectAdminGb ?? 0, 1) }} GB · Containers {{ number_format($diskContainerGb ?? 0, 1) }} GB</p>
                        </div>
                    @endif
                @else
                    <p class="text-sm text-amber-700 mb-4">No active package</p>
                    <a href="{{ route('reseller.packages.index') }}" class="block text-center px-4 py-2 bg-purple-600 text-white rounded-lg text-sm">Choose package</a>
                @endif
            </div>

            @if (!empty($marginSummary))
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <div class="flex justify-between mb-3">
                        <h3 class="font-semibold text-slate-900 dark:text-white">Margins (30d)</h3>
                        <a href="{{ route('reseller.reports.index') }}" class="text-xs text-purple-600">Reports</a>
                    </div>
                    @if ($marginSummary['avg_monthly_margin'] !== null)
                        <p class="text-sm text-slate-600">Avg catalog margin / mo</p>
                        <p class="text-xl font-bold text-emerald-600">KSH {{ number_format($marginSummary['avg_monthly_margin'], 2) }}</p>
                    @endif
                    <p class="text-sm text-slate-600 mt-3">Domain orders margin (30d)</p>
                    <p class="text-lg font-semibold">KSH {{ number_format($marginSummary['domain_margin_30d'] ?? 0, 2) }}</p>
                    @if (!empty($ledgerMargin30d['margin_total']))
                        <p class="text-sm text-slate-600 mt-3">Earned from payments (30d)</p>
                        <p class="text-lg font-semibold text-emerald-600">KSH {{ number_format($ledgerMargin30d['margin_total'], 2) }}</p>
                    @endif
                    <p class="text-xs text-slate-500 mt-2">Platform commission (display only): KSH {{ number_format($totalCommission, 2) }} at {{ number_format($commissionRate, 1) }}%</p>
                </div>
            @endif

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex justify-between">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Customers</h3>
                    <a href="{{ route('reseller.customers.index') }}" class="text-xs text-purple-600">All</a>
                </div>
                @forelse ($managedCustomers->take(6) as $customer)
                    <a href="{{ route('reseller.customers.show', $customer) }}" class="block p-4 border-b border-slate-100 dark:border-slate-800 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-800">
                        <p class="font-medium text-sm">{{ $customer->name }}</p>
                        <p class="text-xs text-slate-500">{{ $customer->email }}</p>
                    </a>
                @empty
                    <p class="p-6 text-sm text-slate-500 text-center">No customers yet.</p>
                @endforelse
            </div>

            @if (!empty($registrationInviteUrl))
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6" x-data="{ copied: false }">
                    <h3 class="font-semibold text-slate-900 dark:text-white mb-2">Whitelabel signup link</h3>
                    <p class="text-xs text-slate-500 break-all mb-3">{{ $registrationInviteUrl }}</p>
                    <div class="flex gap-2">
                        <button type="button" @click="navigator.clipboard.writeText(@js($registrationInviteUrl)); copied=true; setTimeout(()=>copied=false,2000)" class="text-xs px-3 py-1.5 bg-purple-600 text-white rounded-lg">Copy link</button>
                        <span x-show="copied" x-cloak class="text-xs text-emerald-600 self-center">Copied!</span>
                        <a href="{{ route('reseller.settings.index') }}" class="text-xs text-purple-600 self-center">Branding →</a>
                    </div>
                </div>
            @endif

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="font-semibold text-slate-900 dark:text-white mb-4">Invoice breakdown</h3>
                <div class="grid grid-cols-3 gap-2 text-center text-sm">
                    <div class="rounded-lg bg-emerald-50 dark:bg-emerald-950 p-2"><span class="block font-bold text-emerald-600">{{ $invoiceStatus['paid'] ?? 0 }}</span><span class="text-xs text-slate-500">Paid</span></div>
                    <div class="rounded-lg bg-amber-50 dark:bg-amber-950 p-2"><span class="block font-bold text-amber-600">{{ $invoiceStatus['unpaid'] ?? 0 }}</span><span class="text-xs text-slate-500">Unpaid</span></div>
                    <div class="rounded-lg bg-red-50 dark:bg-red-950 p-2"><span class="block font-bold text-red-600">{{ $invoiceStatus['overdue'] ?? 0 }}</span><span class="text-xs text-slate-500">Overdue</span></div>
                </div>
                <div class="flex items-end gap-2 h-20 mt-4">
                    @foreach ($monthlyRevenue ?? [] as $amount)
                        @php $height = ($amount > 0 && max($monthlyRevenue) > 0) ? max(8, ($amount / max($monthlyRevenue)) * 100) : 8; @endphp
                        <div class="flex-1 bg-purple-500/80 rounded-t" style="height: {{ $height }}%" title="KSH {{ number_format($amount, 0) }}"></div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
