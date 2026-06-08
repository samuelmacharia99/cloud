@extends('layouts.customer')

@section('title', 'My Dashboard')

@section('content')
<div class="space-y-8">
    <!-- Welcome -->
    <div class="hero-banner p-6 sm:p-8">
        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-brand-100 text-sm font-semibold uppercase tracking-wider">Customer portal</p>
                <h1 class="text-2xl sm:text-3xl font-bold mt-1">Welcome back, {{ auth()->user()->name }}</h1>
                <p class="text-blue-100/90 mt-2 max-w-xl text-sm sm:text-base">Manage services, domains, invoices, and support — all in one place.</p>
            </div>
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/15 backdrop-blur-sm border border-white/20 text-sm font-semibold shrink-0">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                Account active
            </div>
        </div>
    </div>

    <!-- Quick stats -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 sm:gap-6">
        <x-metric-card
            title="Active Services"
            :value="$activeServices->count()"
            subtitle="Running now"
            color="emerald"
            :href="route('customer.services.index')"
            :icon="'<svg class=\"w-6 h-6\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M13 10V3L4 14h7v7l9-11h-7z\"/></svg>'"
        />
        <x-metric-card
            title="Unpaid Invoices"
            :value="$upcomingDueInvoices->count()"
            subtitle="Awaiting payment"
            color="amber"
            :href="route('customer.invoices.index')"
            :icon="'<svg class=\"w-6 h-6\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z\"/></svg>'"
        />
        <x-metric-card
            title="Outstanding Balance"
            :value="'KES '.number_format($outstandingBalance, 0)"
            :subtitle="$outstandingBalance > 0 ? 'Due soon' : 'All paid up'"
            :color="$outstandingBalance > 0 ? 'amber' : 'emerald'"
            :href="route('customer.invoices.index')"
            :icon="'<svg class=\"w-6 h-6\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z\"/></svg>'"
        />
        <x-metric-card
            title="Open Tickets"
            :value="$openTickets->count()"
            subtitle="Need help?"
            color="red"
            :href="route('customer.tickets.index')"
            :icon="'<svg class=\"w-6 h-6\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z\"/></svg>'"
        />
    </div>

    @if ($suspendedServices->count() > 0 || $provisioningServices->count() > 0 || $expiringDomains->count() > 0)
    <div class="space-y-3">
        @if ($suspendedServices->count() > 0)
            <div class="ui-card p-4 border-orange-200 dark:border-orange-800 bg-orange-50/80 dark:bg-orange-950/30 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-orange-900 dark:text-orange-200"><strong>{{ $suspendedServices->count() }}</strong> service(s) suspended — pay outstanding invoices to restore access.</p>
                <a href="{{ route('customer.invoices.index') }}" class="btn-sm btn-primary">View invoices</a>
            </div>
        @endif
        @if ($provisioningServices->count() > 0)
            <div class="ui-card p-4 border-blue-200 dark:border-blue-800 bg-blue-50/80 dark:bg-blue-950/30">
                <p class="text-sm text-blue-900 dark:text-blue-200"><strong>{{ $provisioningServices->count() }}</strong> service(s) provisioning — we'll notify you when ready.</p>
            </div>
        @endif
        @if ($expiringDomains->count() > 0)
            <div class="ui-card p-4 border-amber-200 dark:border-amber-800 bg-amber-50/80 dark:bg-amber-950/30 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-amber-900 dark:text-amber-200"><strong>{{ $expiringDomains->count() }}</strong> domain(s) expiring within 30 days.</p>
                <a href="{{ route('customer.domains.index') }}" class="btn-sm btn-primary">Manage domains</a>
            </div>
        @endif
    </div>
    @endif

    <!-- Quick actions -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        @if ($isResellerManaged)
        <a href="{{ route('customer.reseller-catalog.index') }}" class="ui-card ui-card-interactive p-4 flex items-center gap-3 group">
            <span class="w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center group-hover:scale-105 transition-transform">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m0 0l8-4m0 0v10l-8 4m0-10L4 7m0 10v10l8 4m8-4v-10l-8-4"/></svg>
            </span>
            <div>
                <p class="font-semibold text-slate-900 dark:text-white text-sm">Order services</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Reseller catalog</p>
            </div>
        </a>
        @else
        <a href="{{ route('customer.select-techstack') }}" class="ui-card ui-card-interactive p-4 flex items-center gap-3 group">
            <span class="w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center group-hover:scale-105 transition-transform">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            </span>
            <div>
                <p class="font-semibold text-slate-900 dark:text-white text-sm">Deploy service</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Hosting & containers</p>
            </div>
        </a>
        @endif
        <a href="{{ route('customer.domains.index') }}" class="ui-card ui-card-interactive p-4 flex items-center gap-3 group">
            <span class="w-10 h-10 rounded-xl bg-brand-100 dark:bg-brand-950/60 text-brand-600 dark:text-brand-400 flex items-center justify-center group-hover:scale-105 transition-transform">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            </span>
            <div>
                <p class="font-semibold text-slate-900 dark:text-white text-sm">Manage domains</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Register & renew</p>
            </div>
        </a>
        <a href="{{ route('customer.invoices.index') }}" class="ui-card ui-card-interactive p-4 flex items-center gap-3 group">
            <span class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center group-hover:scale-105 transition-transform">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
            </span>
            <div>
                <p class="font-semibold text-slate-900 dark:text-white text-sm">Pay invoice</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">M-Pesa, card & more</p>
            </div>
        </a>
    </div>

    <!-- Content grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <x-dashboard-section title="Recent Invoices" :href="route('customer.invoices.index')" action_text="View all">
                @if ($upcomingDueInvoices->count() > 0)
                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($upcomingDueInvoices->take(5) as $invoice)
                            <a href="{{ route('customer.invoices.show', $invoice) }}" class="flex items-center justify-between gap-4 px-5 py-4 sm:px-6 hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors">
                                <div class="min-w-0">
                                    <p class="font-semibold text-slate-900 dark:text-white truncate">{{ $invoice->invoice_number }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Due {{ $invoice->due_date->format('M d, Y') }}</p>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="font-bold text-slate-900 dark:text-white">KES {{ number_format($invoice->total, 0) }}</p>
                                    <div class="mt-1"><x-status-badge :status="$invoice->status" type="invoice" /></div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="px-6 py-10 text-center text-sm text-slate-500 dark:text-slate-400">All invoices paid — you're all set.</div>
                @endif
            </x-dashboard-section>

            <x-dashboard-section title="My Services" :href="route('customer.services.index')" action_text="View all">
                @if ($activeServices->count() > 0)
                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($activeServices->take(5) as $service)
                            <a href="{{ route('customer.services.show', $service) }}" class="flex items-center justify-between gap-4 px-5 py-4 sm:px-6 hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors">
                                <div class="min-w-0">
                                    <p class="font-semibold text-slate-900 dark:text-white truncate">{{ $service->product?->name ?? 'Service' }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Renews {{ $service->next_due_date?->format('M d, Y') ?? 'N/A' }}</p>
                                </div>
                                <x-status-badge :status="$service->status" type="service" />
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="px-6 py-10 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">No active services yet.</p>
                        <a href="{{ route('customer.select-techstack') }}" class="btn-primary btn-sm">Deploy your first service</a>
                    </div>
                @endif
            </x-dashboard-section>
        </div>

        <div class="space-y-6">
            <div class="ui-card p-6">
                <h3 class="font-semibold text-slate-900 dark:text-white mb-4">Account</h3>
                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Email</dt>
                        <dd class="font-medium text-slate-900 dark:text-white mt-0.5 truncate">{{ auth()->user()->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Status</dt>
                        <dd class="mt-1"><span class="status-pill bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300"><span class="status-pill-dot bg-emerald-500"></span>Active</span></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Member since</dt>
                        <dd class="font-medium text-slate-900 dark:text-white mt-0.5">{{ auth()->user()->created_at->format('M d, Y') }}</dd>
                    </div>
                    @if ($creditBalance > 0)
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Account credit</dt>
                        <dd class="font-medium text-emerald-600 dark:text-emerald-400 mt-0.5">KES {{ number_format($creditBalance, 2) }}</dd>
                    </div>
                    @endif
                </dl>
            </div>

            <div class="hero-banner p-6">
                <div class="relative">
                    <h3 class="font-semibold text-lg">Need help?</h3>
                    <p class="text-sm text-blue-100/90 mt-1 mb-4">Our support team is ready to assist you.</p>
                    <a href="{{ route('customer.tickets.index') }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-white/95 hover:bg-white text-brand-700 font-semibold rounded-xl text-sm transition shadow-sm">
                        Open support ticket
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
