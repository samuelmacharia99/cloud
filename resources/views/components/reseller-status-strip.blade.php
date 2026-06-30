@props([
    'billingHealth' => [],
    'walletBalance' => null,
    'walletIsLow' => false,
    'walletCurrency' => 'KSH',
    'packageExpiresAt' => null,
    'daysUntilPackageExpiry' => null,
    'hasDirectAdmin' => false,
    'unlinkedDaCount' => 0,
    'activeServices' => 0,
    'maxServices' => 0,
    'customerCount' => 0,
    'maxUsers' => 0,
    'diskPoolPercent' => null,
])

@php
    $severity = $billingHealth['severity'] ?? 'success';
    $badgeClasses = match ($severity) {
        'danger' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800',
        'warning' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800',
        default => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
    };
    $dotClasses = match ($severity) {
        'danger' => 'bg-red-500',
        'warning' => 'bg-amber-500',
        default => 'bg-emerald-500',
    };
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
    <div class="flex items-center gap-3 p-4 rounded-xl border {{ $badgeClasses }}">
        <span class="w-2.5 h-2.5 rounded-full {{ $dotClasses }} shrink-0"></span>
        <div class="min-w-0">
            <p class="text-xs font-medium uppercase tracking-wide opacity-80">Account</p>
            <p class="text-sm font-semibold truncate">{{ $billingHealth['sidebar_label'] ?? 'Active' }}</p>
            @if (! empty($billingHealth['pending_own_invoice_url']))
                <a href="{{ $billingHealth['pending_own_invoice_url'] }}" class="text-xs underline mt-0.5 inline-block">Pay subscription →</a>
            @endif
        </div>
    </div>

    @isset($walletBalance)
        <a href="{{ route('reseller.wallet.index') }}" class="flex items-center gap-3 p-4 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 hover:border-purple-300 dark:hover:border-purple-700 transition">
            <div class="min-w-0 flex-1">
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Wallet</p>
                <p class="text-sm font-bold text-slate-900 dark:text-white">{{ $walletCurrency }} {{ number_format($walletBalance, 2) }}</p>
                @if ($walletIsLow)
                    <p class="text-xs text-amber-600 mt-0.5">Low balance</p>
                @endif
            </div>
        </a>
    @endisset

    <a href="{{ route('reseller.packages.index') }}" class="flex items-center gap-3 p-4 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 hover:border-purple-300 dark:hover:border-purple-700 transition">
        <div class="min-w-0 flex-1">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Package</p>
            @if ($packageExpiresAt)
                <p class="text-sm font-semibold text-slate-900 dark:text-white">Expires {{ $packageExpiresAt->format('M j, Y') }}</p>
                @if ($daysUntilPackageExpiry !== null)
                    <p class="text-xs text-slate-500">{{ $daysUntilPackageExpiry >= 0 ? $daysUntilPackageExpiry.' days left' : abs($daysUntilPackageExpiry).' days overdue' }}</p>
                @endif
            @else
                <p class="text-sm font-semibold text-amber-600">No package</p>
            @endif
        </div>
    </a>

    <a href="{{ $hasDirectAdmin ? route('reseller.customers.index', ['link' => $unlinkedDaCount > 0 ? 'unlinked' : 'all']) : route('reseller.settings.index', ['tab' => 'hosting']) }}" class="flex items-center gap-3 p-4 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 hover:border-purple-300 dark:hover:border-purple-700 transition">
        <div class="min-w-0 flex-1">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">DirectAdmin</p>
            @if ($hasDirectAdmin)
                <p class="text-sm font-semibold text-emerald-600">Connected</p>
                <p class="text-xs text-slate-500">{{ $unlinkedDaCount > 0 ? $unlinkedDaCount.' unlinked account(s)' : 'All accounts linked' }}</p>
            @else
                <p class="text-sm font-semibold text-slate-600 dark:text-slate-400">Not connected</p>
            @endif
        </div>
    </a>
</div>

@if ($maxServices > 0 || $maxUsers > 0 || ($diskPoolPercent !== null && $diskPoolPercent > 0))
<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
    @if ($maxServices > 0)
        <div class="p-3 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-xs">
            <div class="flex justify-between mb-1"><span class="text-slate-500">Service slots</span><span>{{ $activeServices }}/{{ $maxServices }}</span></div>
            <div class="h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                <div class="h-full bg-purple-500 rounded-full" style="width: {{ min(100, $maxServices > 0 ? round(($activeServices / $maxServices) * 100) : 0) }}%"></div>
            </div>
        </div>
    @endif
    @if ($maxUsers > 0)
        <div class="p-3 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-xs">
            <div class="flex justify-between mb-1"><span class="text-slate-500">Hosted users</span><span>{{ $customerCount }}/{{ $maxUsers }}</span></div>
            <div class="h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                <div class="h-full bg-purple-500 rounded-full" style="width: {{ min(100, $maxUsers > 0 ? round(($customerCount / $maxUsers) * 100) : 0) }}%"></div>
            </div>
        </div>
    @endif
    @if ($diskPoolPercent !== null && $diskPoolPercent > 0)
        <div class="p-3 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-xs">
            <div class="flex justify-between mb-1"><span class="text-slate-500">Disk pool</span><span>{{ rtrim(rtrim(number_format($diskPoolPercent, 1), '0'), '.') }}%</span></div>
            <div class="h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                <div class="h-full {{ $diskPoolPercent >= 90 ? 'bg-amber-500' : 'bg-emerald-500' }} rounded-full" style="width: {{ min(100, $diskPoolPercent) }}%"></div>
            </div>
        </div>
    @endif
</div>
@endif
