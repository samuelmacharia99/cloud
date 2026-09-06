@extends('layouts.reseller')

@section('title', $domain->fqdn().' - DNS')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('reseller.domains.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Domains</a>
    <span class="text-slate-400">/</span>
    <a href="{{ route('reseller.domains.show', $domain) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-mono">{{ $domain->fqdn() }}</a>
    <span class="text-slate-400">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">DNS</p>
</div>
@endsection

@section('content')
    @include('domains.partials.dns-manager', [
        'domain' => $domain,
        'records' => $records,
        'zone' => $zone,
        'usesDirectAdmin' => $usesDirectAdmin ?? false,
        'cloudflareAvailable' => $cloudflareAvailable ?? false,
        'canProvision' => $canProvision ?? false,
        'dnsRoutePrefix' => 'reseller.domains.dns',
        'domainShowUrl' => route('reseller.domains.show', ['domain' => $domain, 'tab' => 'registry']),
        'nameserverUrl' => route('reseller.domains.show', ['domain' => $domain, 'tab' => 'registry']),
        'primaryButtonClass' => 'bg-purple-600 hover:bg-purple-700',
    ])
@endsection
