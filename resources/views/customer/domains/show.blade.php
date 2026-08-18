@extends('layouts.customer')

@section('title', $domain->name.$domain->extension)

@section('content')
<div class="space-y-6 max-w-4xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white font-mono">{{ $domain->name }}{{ $domain->extension }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Registration, nameservers, EPP, and WHOIS</p>
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
        @unless($domain->isDnsManaged() || $cloudflareManaged)
            <div>
                <div class="flex items-start justify-between gap-3 mb-1">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">Nameservers</h2>
                    @if(! empty($registry['nameservers_live']))
                        <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">Live from registry</span>
                    @endif
                </div>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">At least two unique nameservers are required. Saving pushes the change to the registry.</p>
                <form method="POST" action="{{ route('customer.domains.nameservers', $domain) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @csrf
                    @method('PUT')
                    @foreach(['nameserver_1' => 'Nameserver 1', 'nameserver_2' => 'Nameserver 2', 'nameserver_3' => 'Nameserver 3', 'nameserver_4' => 'Nameserver 4'] as $field => $label)
                        <div>
                            <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">{{ $label }}</label>
                            <input type="text" name="{{ $field }}" value="{{ old($field, $nameservers[$field] ?? $domain->{$field}) }}"
                                class="w-full px-3 py-2 font-mono text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600"
                                @if(in_array($field, ['nameserver_1', 'nameserver_2'], true)) required @endif>
                            @error($field)<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                    <div class="md:col-span-2">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Save nameservers</button>
                    </div>
                </form>
            </div>
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
