@extends('layouts.customer')

@section('title', $domain->name.$domain->extension)

@section('content')
<div class="space-y-6 max-w-4xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white font-mono">{{ $domain->name }}{{ $domain->extension }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">
                @if($cloudflareManaged ?? false)
                    Registry nameservers, EPP, and WHOIS — DNS records are on the DNS page (Cloudflare).
                @else
                    Registration, nameservers, EPP, and WHOIS
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('customer.domains.index') }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm font-medium">← Domains</a>
            <a href="{{ route('customer.domains.dns.index', $domain) }}" class="px-4 py-2 bg-slate-800 text-white rounded-lg text-sm font-medium">DNS</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="ui-card p-6">
            <p class="text-sm text-slate-500">Status</p>
            <div class="mt-2"><x-domain-status-badge :status="$domain->status" /></div>
        </div>
        <div class="ui-card p-6">
            <p class="text-sm text-slate-500">Expires</p>
            <p class="mt-2 font-semibold {{ $domain->isExpired() ? 'text-red-600' : 'text-slate-900 dark:text-white' }}">{{ $domain->expires_at?->format('M d, Y') ?? '—' }}</p>
        </div>
        <div class="ui-card p-6">
            <p class="text-sm text-slate-500">Auto-renew</p>
            <p class="mt-2 font-semibold">{{ $domain->auto_renew ? 'On' : 'Off' }}</p>
        </div>
    </div>

    <div class="ui-card p-6 space-y-8">
        @if(session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 dark:bg-emerald-950/30 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">{{ session('success') }}</div>
        @endif
        @if(session('warning'))
            <div class="rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-800 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">{{ session('warning') }}</div>
        @endif
        @if(session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-950/30 dark:border-red-800 px-4 py-3 text-sm text-red-800 dark:text-red-200">{{ session('error') }}</div>
        @endif

        @unless($domain->isDnsManaged())
            @include('customer.domains.partials.nameserver-form', [
                'domain' => $domain,
                'nameservers' => $nameservers,
                'registry' => $registry,
                'cloudflareManaged' => $cloudflareManaged,
                'usesDirectAdmin' => $usesDirectAdmin ?? false,
            ])
            <hr class="border-slate-200 dark:border-slate-700">
        @endunless

        @include('domains.partials.registry-management', [
            'audience' => 'customer',
            'registrantRoute' => $domain->isDnsManaged() ? null : route('customer.domains.registrant', $domain),
            'optionsRoute' => $domain->isDnsManaged() ? null : route('customer.domains.registry-options', $domain),
            'cloudflareManaged' => $cloudflareManaged,
        ])
    </div>
</div>
@endsection
