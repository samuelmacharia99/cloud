@php
    $hosting = $hostingSettings ?? [];
@endphp

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
    <div class="bg-gradient-to-r from-purple-600 to-purple-700 px-6 py-4">
        <h3 class="text-lg font-bold text-white">DirectAdmin & hosting</h3>
        <p class="text-sm text-purple-100 mt-1">Connection status and account linking for your whitelabel hosting.</p>
    </div>
    <div class="p-6 space-y-6">
        @if ($hosting['connected'] ?? false)
            <div class="flex items-start gap-3 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 mt-1.5 shrink-0"></span>
                <div>
                    <p class="font-semibold text-emerald-800 dark:text-emerald-200">DirectAdmin connected</p>
                    <p class="text-sm text-emerald-700 dark:text-emerald-300 mt-1">
                        Reseller user: <span class="font-mono">{{ $hosting['username'] ?? '—' }}</span>
                        @if (! empty($hosting['node_name']))
                            · Node: {{ $hosting['node_name'] }}
                        @endif
                    </p>
                </div>
            </div>

            @if (($hosting['unlinked_count'] ?? 0) > 0)
                <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800">
                    <p class="text-sm font-medium text-amber-900 dark:text-amber-100">{{ $hosting['unlinked_count'] }} DirectAdmin account(s) are not linked to the platform yet.</p>
                    <a href="{{ route('reseller.customers.index', ['link' => 'unlinked']) }}" class="inline-block mt-2 text-sm font-medium text-purple-600">Link accounts from customer list →</a>
                </div>
            @else
                <p class="text-sm text-slate-600 dark:text-slate-400">All known DirectAdmin users are linked to platform customers.</p>
            @endif

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('reseller.customers.index') }}" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-lg">Customer directory</a>
                <a href="{{ route('reseller.customers.index', ['refresh' => 1]) }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 text-sm font-medium rounded-lg">Refresh from DirectAdmin</a>
                <a href="{{ route('dashboard') }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 text-sm font-medium rounded-lg">Server pulse on dashboard</a>
            </div>
        @else
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700">
                <p class="font-medium text-slate-900 dark:text-white">DirectAdmin not connected</p>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-2">Platform administrators connect your reseller account to a DirectAdmin node. After connection you can link existing hosted users and enable auto-billing from your customer list.</p>
                <ol class="mt-4 space-y-2 text-sm text-slate-600 dark:text-slate-400 list-decimal list-inside">
                    <li>Subscribe to a reseller package with hosting slots</li>
                    <li>Ask Talksasa support to bind your DirectAdmin reseller login</li>
                    <li>Add catalog items with matching DirectAdmin package names</li>
                    <li>Link accounts from <a href="{{ route('reseller.customers.index') }}" class="text-purple-600">Customers</a></li>
                </ol>
            </div>
        @endif

        <p class="text-xs text-slate-500">Nightly reconciliation runs automatically to detect unlinked accounts and package drift. You will be notified when action is needed.</p>
    </div>
</div>
