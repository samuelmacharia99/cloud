@extends('layouts.customer')

@section('title', $domain->name . $domain->extension . ' - DNS Management')

@section('breadcrumb')
<div class="flex items-center gap-2">
    <a href="{{ route('customer.domains.index') }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
        Domains
    </a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <span class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $domain->name }}{{ $domain->extension }}</span>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">DNS Management</p>
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
        'dnsRoutePrefix' => 'customer.domains.dns',
        'domainShowUrl' => route('customer.domains.show', $domain),
        'nameserverUrl' => route('customer.domains.dns.nameservers', $domain),
        'primaryButtonClass' => 'bg-blue-600 hover:bg-blue-700',
    ])
@endsection
