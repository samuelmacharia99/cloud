@extends('layouts.admin')

@section('title', 'Bookkeeping')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Reports</p>
@endsection

@section('content')
@php
    $periodCursor = $month
        ? \Carbon\Carbon::create($year, $month, 1)
        : \Carbon\Carbon::create($year, 1, 1);
    $prevPeriod = $month ? $periodCursor->copy()->subMonth() : $periodCursor->copy()->subYear();
    $nextPeriod = $month ? $periodCursor->copy()->addMonth() : $periodCursor->copy()->addYear();
    $minYear = min($years);
    $prevDisabled = $month
        ? ($prevPeriod->year < $minYear)
        : ($prevPeriod->year < $minYear);
    $nextDisabled = $month
        ? $nextPeriod->copy()->startOfMonth()->gt(now()->copy()->startOfMonth())
        : $nextPeriod->year > now()->year;
    $prevQuery = $month
        ? ['year' => $prevPeriod->year, 'month' => $prevPeriod->month]
        : ['year' => $prevPeriod->year, 'month' => 'all'];
    $nextQuery = $month
        ? ['year' => $nextPeriod->year, 'month' => $nextPeriod->month]
        : ['year' => $nextPeriod->year, 'month' => 'all'];
    $incomeColors = [
        'hosting' => 'bg-blue-500',
        'reseller_subscription' => 'bg-violet-500',
        'domain' => 'bg-teal-500',
        'wallet_topup' => 'bg-amber-500',
        'other' => 'bg-slate-400',
    ];
    $maxMethodTotal = max(1, (float) collect($paymentMethods)->max('total'));
    $maxNodeAbs = max(1, (float) collect($nodes)->max(fn ($row) => abs((float) ($row['profit'] ?? $row['revenue'] ?? 0))));
    $maxRegistrarAbs = max(1, (float) collect($domains['registrars'] ?? [])->max(fn ($row) => abs((float) ($row['profit'] ?? 0))));
@endphp

<div class="space-y-8">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h1 class="page-title text-2xl sm:text-3xl font-bold tracking-tight text-slate-900 dark:text-white">Bookkeeping</h1>
            <p class="mt-1.5 text-sm sm:text-base text-slate-600 dark:text-slate-400 max-w-2xl">Cash collected, provider spend, and profit in base KES for the selected period.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 pb-2">
            <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-sm font-semibold text-slate-800 dark:text-slate-100">
                <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
                {{ $periodLabel }}
            </span>
            @if($prevDisabled)
                <span class="btn-secondary opacity-40 pointer-events-none px-3" aria-disabled="true">Previous</span>
            @else
                <a href="{{ route('admin.reports.index', array_filter($prevQuery)) }}" class="btn-secondary px-3">Previous</a>
            @endif
            @if($nextDisabled)
                <span class="btn-secondary opacity-40 pointer-events-none px-3" aria-disabled="true">Next</span>
            @else
                <a href="{{ route('admin.reports.index', array_filter($nextQuery)) }}" class="btn-secondary px-3">Next</a>
            @endif
        </div>
    </div>

    <form method="GET" action="{{ route('admin.reports.index') }}" class="ui-card p-4 sm:p-5" onchange="if (event.target.tagName === 'SELECT') this.submit()">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex flex-wrap gap-3 items-end">
                <x-form-select
                    label="Year"
                    name="year"
                    :options="collect($years)->mapWithKeys(fn ($y) => [$y => $y])->all()"
                    :value="$year"
                    placeholder=""
                    class="min-w-[7.5rem]"
                />
                <x-form-select
                    label="Month"
                    name="month"
                    :options="$monthOptions"
                    :value="$month ?? 'all'"
                    placeholder=""
                    class="min-w-[11rem]"
                />
                <button type="submit" class="btn-primary">Apply</button>
                <a href="{{ route('admin.reports.index') }}" class="btn-secondary">This month</a>
            </div>
            <p class="text-xs text-slate-500 lg:text-right max-w-md">
                {{ $from->format('M j, Y') }} – {{ $to->format('M j, Y') }}
                · provider cost covers {{ $costMonths }} {{ \Illuminate\Support\Str::plural('month', $costMonths) }}
                (future months are not counted)
            </p>
        </div>
    </form>

    <nav class="flex flex-wrap gap-2" aria-label="Report sections">
        <a href="#bookkeeping-trend-card" class="rounded-full border border-slate-200 dark:border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:border-brand-400 hover:text-brand-700 dark:hover:text-brand-300">Trend</a>
        <a href="#profit-by-node" class="rounded-full border border-slate-200 dark:border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:border-brand-400 hover:text-brand-700 dark:hover:text-brand-300">Nodes</a>
        <a href="#domain-profit" class="rounded-full border border-slate-200 dark:border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:border-brand-400 hover:text-brand-700 dark:hover:text-brand-300">Domains</a>
    </nav>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
        <x-metric-card
            title="Cash in"
            currency="KES"
            :value="number_format($cashIn, 2)"
            color="emerald"
            :subtitle="$paymentCount.' completed platform '.\Illuminate\Support\Str::plural('payment', $paymentCount)"
            :href="route('admin.payments.index', ['status' => 'completed', 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString()])"
            icon='<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
        />
        <x-metric-card
            title="Spend"
            currency="KES"
            :value="number_format($spend, 2)"
            color="amber"
            :subtitle="'Servers '.number_format($costs['nodes'], 2).' · Registrars '.number_format($costs['domains'], 2)"
            icon='<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>'
        />
        <x-metric-card
            title="Profit"
            currency="KES"
            :value="number_format($profit, 2)"
            :color="$profit >= 0 ? 'emerald' : 'red'"
            :subtitle="$marginPercent === null ? 'No cash in this period' : $marginPercent.'% of cash in'"
            icon='<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>'
        />
        <x-metric-card
            title="Outstanding AR"
            currency="KES"
            :value="number_format($outstandingKes, 2)"
            color="amber"
            subtitle="Unpaid platform invoices — not this period’s cash"
            :href="route('admin.invoices.index', ['status' => 'unpaid'])"
            icon='<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>'
        />
    </div>

    @if($costs['nodes_untracked'] > 0 || ($costs['nodes_missing_rate'] ?? 0) > 0 || $costs['domains_missing_cost'] > 0)
        <div class="rounded-2xl border border-amber-200/80 dark:border-amber-900/60 bg-amber-50/90 dark:bg-amber-950/40 px-4 py-3 text-sm text-amber-900 dark:text-amber-200 space-y-1">
            @if($costs['nodes_untracked'] > 0)
                <p>
                    {{ $costs['nodes_untracked'] }} {{ \Illuminate\Support\Str::plural('node', $costs['nodes_untracked']) }} {{ $costs['nodes_untracked'] === 1 ? 'has' : 'have' }} no monthly provider cost —
                    <a href="{{ route('admin.nodes.index') }}" class="font-semibold underline underline-offset-2">set spend on the node</a>.
                </p>
            @endif
            @if(($costs['nodes_missing_rate'] ?? 0) > 0)
                <p>
                    {{ $costs['nodes_missing_rate'] }} {{ \Illuminate\Support\Str::plural('node', $costs['nodes_missing_rate']) }} {{ $costs['nodes_missing_rate'] === 1 ? 'has' : 'have' }} USD spend saved, but KES conversion needs a USD rate —
                    <a href="{{ route('admin.currencies.index') }}" class="font-semibold underline underline-offset-2">set it in Currencies</a>.
                    That spend is excluded from KES totals until the rate is available.
                </p>
            @endif
            @if($costs['domains_missing_cost'] > 0)
                <p>
                    {{ $costs['domains_missing_cost'] }} domain {{ \Illuminate\Support\Str::plural('fulfillment', $costs['domains_missing_cost']) }} {{ $costs['domains_missing_cost'] === 1 ? 'has' : 'have' }} no registrar cost —
                    <a href="{{ route('admin.domains.pricing') }}" class="font-semibold underline underline-offset-2">set TLD costs</a>.
                    Those fulfillments are excluded from domain profit and spend.
                </p>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div id="bookkeeping-trend-card" class="ui-card overflow-hidden xl:col-span-2">
            <div class="ui-card-header">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Cash in vs spend</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">{{ $periodLabel }} · spend is provider cost plus registrar fulfillments</p>
            </div>
            <div class="p-5 sm:p-6">
                <div class="relative h-72">
                    <canvas id="bookkeeping-trend"></canvas>
                </div>
            </div>
        </div>
        <div class="ui-card overflow-hidden">
            <div class="ui-card-header">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Where cash came from</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Wallet top-ups are cash. Later wallet spends are allocations, not extra income.</p>
            </div>
            <div class="p-5 sm:p-6">
                <div class="relative h-44 mb-5">
                    <canvas id="bookkeeping-mix"></canvas>
                </div>
                <dl class="space-y-3 text-sm">
                    @foreach($categoryLabels as $key => $label)
                        @php $share = $cashIn > 0 ? round(($income[$key] / $cashIn) * 100, 1) : 0; @endphp
                        <div>
                            <div class="flex justify-between gap-3 mb-1">
                                <dt class="flex items-center gap-2 text-slate-600 dark:text-slate-400">
                                    <span class="h-2 w-2 rounded-full {{ $incomeColors[$key] ?? 'bg-slate-400' }}"></span>
                                    {{ $label }}
                                </dt>
                                <dd class="tabular-nums font-medium text-slate-900 dark:text-white">KES {{ number_format($income[$key], 2) }}</dd>
                            </div>
                            <div class="h-1.5 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                <div class="h-full rounded-full {{ $incomeColors[$key] ?? 'bg-slate-400' }}" style="width: {{ min(100, $share) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </div>

    <div id="profit-by-node" class="ui-card overflow-hidden">
        <div class="ui-card-header">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Profit by node</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Hosting invoices on the server, plus reseller package fees for resellers assigned here. Wallet-paid package fees are included as allocation.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50/80 dark:bg-slate-800/80">
                    <tr class="text-slate-600 dark:text-slate-300">
                        <th class="px-6 py-3 text-left font-semibold">Node</th>
                        <th class="px-6 py-3 text-left font-semibold">Type</th>
                        <th class="px-6 py-3 text-right font-semibold tabular-nums">Hosting</th>
                        <th class="px-6 py-3 text-right font-semibold tabular-nums">Reseller fees</th>
                        <th class="px-6 py-3 text-right font-semibold tabular-nums">Revenue</th>
                        <th class="px-6 py-3 text-right font-semibold tabular-nums">Provider cost</th>
                        <th class="px-6 py-3 text-right font-semibold tabular-nums">Profit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    @forelse($nodes as $row)
                        @php $bar = min(100, (abs((float) ($row['profit'] ?? 0)) / $maxNodeAbs) * 100); @endphp
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                            <td class="px-6 py-3.5">
                                @if($row['id'])
                                    <a href="{{ route('admin.nodes.show', $row['id']) }}" class="font-medium text-brand-700 dark:text-brand-300 hover:underline">{{ $row['name'] }}</a>
                                @else
                                    <span class="text-slate-500">{{ $row['name'] }}</span>
                                @endif
                                @if($row['profit'] !== null)
                                    <div class="mt-1.5 h-1 w-24 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                        <div class="h-full rounded-full {{ $row['profit'] >= 0 ? 'bg-emerald-500' : 'bg-red-500' }}" style="width: {{ $bar }}%"></div>
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-3.5">
                                <span class="inline-flex items-center rounded-full bg-slate-100 dark:bg-slate-800 px-2 py-0.5 text-xs font-medium text-slate-600 dark:text-slate-300">{{ $row['type_label'] }}</span>
                            </td>
                            <td class="px-6 py-3.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($row['hosting_revenue'], 2) }}</td>
                            <td class="px-6 py-3.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($row['subscription_revenue'], 2) }}</td>
                            <td class="px-6 py-3.5 text-right tabular-nums font-medium text-slate-900 dark:text-white">{{ number_format($row['revenue'], 2) }}</td>
                            <td class="px-6 py-3.5 text-right tabular-nums">
                                @if(($row['cost_status'] ?? null) === 'missing_spend')
                                    <a href="{{ route('admin.nodes.edit', $row['id']) }}" class="text-amber-600 dark:text-amber-400 font-medium hover:underline">Not set</a>
                                @elseif(($row['cost_status'] ?? null) === 'missing_rate')
                                    <span class="text-amber-600 dark:text-amber-400 font-medium">${{ number_format((float) $row['monthly_cost_usd'], 2) }}/mo</span>
                                    <p class="text-xs font-normal text-amber-700 dark:text-amber-300">KES rate missing</p>
                                @else
                                    <span class="text-slate-700 dark:text-slate-300">{{ number_format($row['cost_kes'], 2) }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-3.5 text-right tabular-nums font-semibold {{ ($row['profit'] ?? 0) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                @if($row['profit'] === null)
                                    —
                                @else
                                    {{ number_format($row['profit'], 2) }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-10 text-center text-slate-500">No nodes yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div id="domain-profit" class="ui-card overflow-hidden">
        <div class="ui-card-header">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Domain profit by registrar</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Fulfilled this period. Collected is wholesale. Cost is the current registrar price for that TLD.</p>
                </div>
                <p class="text-sm font-medium text-slate-600 dark:text-slate-300 shrink-0">
                    {{ $domains['count'] }} {{ \Illuminate\Support\Str::plural('fulfillment', $domains['count']) }}
                    · {{ count($domains['registrars']) }} {{ \Illuminate\Support\Str::plural('registrar', count($domains['registrars'])) }}
                </p>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-px bg-slate-200 dark:bg-slate-800">
            <div class="bg-white dark:bg-slate-900 px-6 py-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Collected</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-slate-900 dark:text-white">KES {{ number_format($domains['collected'], 2) }}</p>
            </div>
            <div class="bg-white dark:bg-slate-900 px-6 py-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Registrar cost</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-slate-900 dark:text-white">KES {{ number_format($domains['cost'], 2) }}</p>
            </div>
            <div class="bg-white dark:bg-slate-900 px-6 py-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Profit</p>
                <p class="mt-1 text-xl font-bold tabular-nums {{ $domains['profit'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">KES {{ number_format($domains['profit'], 2) }}</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50/80 dark:bg-slate-800/80">
                    <tr class="text-slate-600 dark:text-slate-300">
                        <th class="px-6 py-3 text-left font-semibold">Registrar</th>
                        <th class="px-6 py-3 text-left font-semibold">Mix</th>
                        <th class="px-6 py-3 text-right font-semibold tabular-nums">Fulfillments</th>
                        <th class="px-6 py-3 text-right font-semibold tabular-nums">Collected</th>
                        <th class="px-6 py-3 text-right font-semibold tabular-nums">Cost</th>
                        <th class="px-6 py-3 text-right font-semibold tabular-nums">Profit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    @forelse($domains['registrars'] as $row)
                        @php $bar = min(100, (abs((float) $row['profit']) / $maxRegistrarAbs) * 100); @endphp
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                            <td class="px-6 py-3.5">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $row['name'] }}</p>
                                @if($row['driver_label'])
                                    <span class="mt-1 inline-flex items-center rounded-full bg-slate-100 dark:bg-slate-800 px-2 py-0.5 text-xs font-medium text-slate-600 dark:text-slate-300">{{ $row['driver_label'] }}</span>
                                @endif
                                <div class="mt-1.5 h-1 w-24 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                    <div class="h-full rounded-full {{ $row['profit'] >= 0 ? 'bg-emerald-500' : 'bg-red-500' }}" style="width: {{ $bar }}%"></div>
                                </div>
                            </td>
                            <td class="px-6 py-3.5 text-slate-600 dark:text-slate-400 text-xs leading-5">
                                {{ $row['registrations'] }} {{ \Illuminate\Support\Str::plural('registration', $row['registrations']) }}
                                · {{ $row['transfers'] }} {{ \Illuminate\Support\Str::plural('transfer', $row['transfers']) }}
                                · {{ $row['renewals'] }} {{ \Illuminate\Support\Str::plural('renewal', $row['renewals']) }}
                            </td>
                            <td class="px-6 py-3.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($row['count']) }}</td>
                            <td class="px-6 py-3.5 text-right tabular-nums font-medium text-slate-900 dark:text-white">{{ number_format($row['collected'], 2) }}</td>
                            <td class="px-6 py-3.5 text-right tabular-nums">
                                @if($row['missing_cost_count'] > 0 && $row['cost'] <= 0)
                                    <span class="text-amber-600 dark:text-amber-400 font-medium">Unknown</span>
                                @else
                                    <span class="text-slate-700 dark:text-slate-300">{{ number_format($row['cost'], 2) }}</span>
                                    @if($row['missing_cost_count'] > 0)
                                        <p class="text-xs font-normal text-amber-700 dark:text-amber-300">{{ $row['missing_cost_count'] }} missing</p>
                                    @endif
                                @endif
                            </td>
                            <td class="px-6 py-3.5 text-right tabular-nums font-semibold {{ $row['profit'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                {{ number_format($row['profit'], 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-10 text-center text-slate-500">No domain registrations, transfers, or renewals fulfilled in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="ui-card overflow-hidden lg:max-w-xl">
        <div class="ui-card-header">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Payments by method</h2>
        </div>
        <div class="p-5 sm:p-6 space-y-4">
            @forelse($paymentMethods as $row)
                @php $pct = min(100, ($row['total'] / $maxMethodTotal) * 100); @endphp
                <div>
                    <div class="flex justify-between gap-3 text-sm mb-1.5">
                        <div>
                            <p class="font-medium text-slate-900 dark:text-white">{{ $row['label'] }}</p>
                            <p class="text-xs text-slate-500">{{ $row['count'] }} {{ \Illuminate\Support\Str::plural('payment', $row['count']) }}</p>
                        </div>
                        <p class="tabular-nums font-semibold text-slate-900 dark:text-white">{{ number_format($row['total'], 2) }}</p>
                    </div>
                    <div class="h-1.5 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                        <div class="h-full rounded-full bg-brand-500" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
            @empty
                <p class="py-6 text-center text-slate-500 text-sm">No completed platform payments in this period.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    const isDark = document.documentElement.classList.contains('dark');
    const tick = isDark ? '#94a3b8' : '#64748b';
    const grid = isDark ? 'rgba(51, 65, 85, 0.6)' : 'rgba(226, 232, 240, 0.9)';
    const labels = @json($chart['labels']);
    const revenueData = @json($chart['revenue']);
    const spendData = @json($chart['spend']);
    const profitData = @json($chart['profit']);
    const income = @json($income);
    const kesTick = (value) => 'KES ' + Number(value).toLocaleString();

    const trend = document.getElementById('bookkeeping-trend');
    if (trend) {
        new Chart(trend, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Cash in',
                        data: revenueData,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.12)',
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#059669',
                    },
                    {
                        label: 'Spend',
                        data: spendData,
                        borderColor: '#d97706',
                        backgroundColor: 'rgba(217, 119, 6, 0.08)',
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#d97706',
                    },
                    {
                        label: 'Profit',
                        data: profitData,
                        borderColor: '#2563eb',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [6, 4],
                        fill: false,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#2563eb',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { color: tick, boxWidth: 10, boxHeight: 10, usePointStyle: true, font: { size: 12, weight: '500' } },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: tick, callback: kesTick, maxTicksLimit: 6 },
                        grid: { color: grid, drawBorder: false },
                        border: { display: false },
                    },
                    x: {
                        ticks: { color: tick, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 },
                        grid: { display: false },
                        border: { display: false },
                    },
                },
            },
        });
    }

    const mix = document.getElementById('bookkeeping-mix');
    if (mix) {
        new Chart(mix, {
            type: 'doughnut',
            data: {
                labels: ['Hosting & services', 'Reseller packages', 'Domain invoices', 'Wallet top-ups', 'Other'],
                datasets: [{
                    data: [income.hosting, income.reseller_subscription, income.domain, income.wallet_topup, income.other],
                    backgroundColor: ['#3b82f6', '#8b5cf6', '#14b8a6', '#f59e0b', '#94a3b8'],
                    borderWidth: 0,
                    hoverOffset: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: { legend: { display: false } },
            },
        });
    }
</script>
@endpush
