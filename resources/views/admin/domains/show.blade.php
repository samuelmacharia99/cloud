@extends('layouts.admin')

@section('title', $domain->name)

@section('breadcrumb')
<div class="flex items-center gap-2">
    <a href="{{ route('admin.domains.index') }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Domains</a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $domain->name }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6 max-w-4xl">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $domain->name }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Domain details and management</p>
        </div>
        <a href="{{ route('admin.domains.edit', $domain) }}" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
            Edit
        </a>
    </div>

    <!-- Status Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Status</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white mt-2">{{ ucfirst($domain->status) }}</p>
            <span class="inline-block mt-3 px-3 py-1 {{ $domain->status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($domain->status === 'expired' ? 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300' : 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300') }} rounded-full text-xs font-medium">
                {{ ucfirst($domain->status) }}
            </span>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Registered</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white mt-2">{{ $domain->registered_at ? $domain->registered_at->format('M d, Y') : '—' }}</p>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">{{ $domain->registered_at ? $domain->registered_at->diffForHumans() : 'Unknown' }}</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Expires</p>
            <p class="text-2xl font-bold {{ $domain->isExpired() ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }} mt-2">
                {{ $domain->expires_at->format('M d, Y') }}
            </p>
            <p class="text-xs {{ $domain->isExpired() ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }} mt-2">
                {{ $domain->daysUntilExpiry() }} days {{ $domain->isExpired() ? 'overdue' : 'remaining' }}
            </p>
        </div>
    </div>

    <!-- Domain Details -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-6">
        <div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Domain Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Extension</p>
                    <p class="text-slate-900 dark:text-white mt-1">{{ $domain->extension ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Registrar</p>
                    <p class="text-slate-900 dark:text-white mt-1">{{ $domain->registrar ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Owner</p>
                    <p class="text-slate-900 dark:text-white mt-1">{{ $domain->user->name }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $domain->user->email }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Auto Renewal</p>
                    <p class="text-slate-900 dark:text-white mt-1">{{ $domain->auto_renew ? 'Enabled' : 'Disabled' }}</p>
                </div>
            </div>
        </div>

        <hr class="border-slate-200 dark:border-slate-700">

        <div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Nameservers</h2>
            <div class="grid grid-cols-1 gap-4">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Nameserver 1</p>
                    <p class="text-slate-900 dark:text-white mt-1 font-mono">{{ $domain->nameserver_1 ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Nameserver 2</p>
                    <p class="text-slate-900 dark:text-white mt-1 font-mono">{{ $domain->nameserver_2 ?? '—' }}</p>
                </div>
            </div>
        </div>

        @if ($domain->notes)
            <hr class="border-slate-200 dark:border-slate-700">
            <div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Notes</h2>
                <p class="text-slate-700 dark:text-slate-300">{{ $domain->notes }}</p>
            </div>
        @endif
    </div>

    <!-- DNS Zones -->
    @if ($domain->dnsZones->count() > 0)
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">DNS Records ({{ $domain->dnsZones->count() }})</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-slate-700 dark:text-slate-300">Type</th>
                            <th class="px-4 py-2 text-left font-medium text-slate-700 dark:text-slate-300">Host</th>
                            <th class="px-4 py-2 text-left font-medium text-slate-700 dark:text-slate-300">Value</th>
                            <th class="px-4 py-2 text-left font-medium text-slate-700 dark:text-slate-300">TTL</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($domain->dnsZones as $zone)
                            <tr>
                                <td class="px-4 py-2 text-slate-900 dark:text-white">{{ $zone->type }}</td>
                                <td class="px-4 py-2 text-slate-900 dark:text-white font-mono">{{ $zone->host }}</td>
                                <td class="px-4 py-2 text-slate-600 dark:text-slate-400 font-mono text-xs">{{ $zone->value }}</td>
                                <td class="px-4 py-2 text-slate-600 dark:text-slate-400">{{ $zone->ttl ?? '3600' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Timestamps -->
    <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-4 text-xs text-slate-600 dark:text-slate-400">
        <p>Created {{ $domain->created_at->diffForHumans() }} • Updated {{ $domain->updated_at->diffForHumans() }}</p>
    </div>
</div>
@endsection
