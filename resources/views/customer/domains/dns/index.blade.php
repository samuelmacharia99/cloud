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
@php
    $fqdn = strtolower($domain->name.$domain->extension);
    $relativeName = function (string $name) use ($fqdn): string {
        $name = strtolower(rtrim($name, '.'));
        if ($name === $fqdn || $name === '') {
            return '@';
        }
        $suffix = '.'.$fqdn;
        if (str_ends_with($name, $suffix)) {
            return substr($name, 0, -strlen($suffix));
        }

        return $name;
    };
    $proxyableTypes = ['A', 'AAAA', 'CNAME'];
@endphp
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">DNS Management</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">{{ $domain->name }}{{ $domain->extension }}</p>
        </div>
        @unless($usesDirectAdmin ?? false)
            <a href="{{ route('customer.domains.dns.nameservers', $domain) }}" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition inline-block text-center">
                View Nameservers
            </a>
        @endunless
    </div>

    @if(session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 dark:bg-emerald-950/30 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-950/30 dark:border-red-800 px-4 py-3 text-sm text-red-800 dark:text-red-200">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-950/30 dark:border-red-800 px-4 py-3 text-sm text-red-800 dark:text-red-200">
            <ul class="list-disc pl-5 space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($usesDirectAdmin ?? false)
        <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6">
            <h3 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">DNS managed via shared hosting</h3>
            <p class="text-sm text-blue-800 dark:text-blue-300">This domain is linked to a DirectAdmin hosting account. Manage DNS records from your hosting control panel instead.</p>
        </div>
    @elseif($zone || $domain->cloudflare_dns_enabled)
        <div
            class="space-y-6"
            x-data="{
                editingId: null,
                addType: '{{ old('type', 'A') }}',
                addProxied: {{ old('proxied') ? 'true' : 'false' }},
                proxyable: {{ Js::from($proxyableTypes) }},
                canProxy(type) { return this.proxyable.includes(String(type || '').toUpperCase()); }
            }"
        >
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-1">Add DNS Record</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Changes apply on Cloudflare immediately. Proxy (orange cloud) is available for A, AAAA, and CNAME.</p>
                <form action="{{ route('customer.domains.dns.add-record', $domain) }}" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Name / Host</label>
                        <input type="text" name="name" value="{{ old('name') }}" placeholder="@ or www" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Type</label>
                        <select name="type" x-model="addType" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" required>
                            @foreach (['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'] as $type)
                                <option value="{{ $type }}" @selected(old('type', 'A') === $type)>{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Content</label>
                        <input type="text" name="content" value="{{ old('content') }}" placeholder="IP address, hostname, or text value" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">TTL (seconds)</label>
                        <input type="number" name="ttl" value="{{ old('ttl', 3600) }}" min="1" max="86400" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" :disabled="canProxy(addType) && addProxied">
                        <p class="text-xs text-slate-500 mt-1" x-show="canProxy(addType) && addProxied" x-cloak>TTL becomes Auto when proxy is on.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Priority (MX / SRV)</label>
                        <input type="number" name="priority" value="{{ old('priority') }}" placeholder="Optional" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                    </div>
                    <div class="md:col-span-2" x-show="canProxy(addType)" x-cloak>
                        <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input type="hidden" name="proxied" value="0">
                            <input type="checkbox" name="proxied" value="1" class="rounded border-slate-300 dark:border-slate-600" x-model="addProxied">
                            <span>Proxy through Cloudflare (orange cloud)</span>
                        </label>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Hides origin IP and enables CDN/WAF. Turn off (DNS only) for mail, FTP, or direct origin access.</p>
                    </div>
                    <div class="md:col-span-2">
                        <button type="submit" class="w-full px-6 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition">Add Record</button>
                    </div>
                </form>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between gap-3">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">DNS Records</h2>
                    <span class="text-xs text-slate-500 dark:text-slate-400">Powered by Cloudflare</span>
                </div>
                @if($records->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[720px]">
                            <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Name</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Type</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Content</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">TTL</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Proxy</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                @foreach($records as $record)
                                    @php
                                        $recordId = (string) ($record['id'] ?? '');
                                        $recordNameFqdn = (string) ($record['name'] ?? '');
                                        $recordName = $relativeName($recordNameFqdn);
                                        $recordType = strtoupper((string) ($record['type'] ?? ''));
                                        $recordContent = (string) ($record['content'] ?? '');
                                        $recordTtl = (int) ($record['ttl'] ?? 3600);
                                        $recordPriority = $record['priority'] ?? null;
                                        $recordProxied = (bool) ($record['proxied'] ?? false);
                                        $canProxy = in_array($recordType, $proxyableTypes, true);
                                    @endphp
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/60 align-top" x-show="editingId !== '{{ $recordId }}'">
                                        <td class="px-4 py-3 text-sm font-medium text-slate-900 dark:text-white font-mono">{{ $recordName }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300">{{ $recordType }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300 break-all max-w-xs">{{ $recordContent }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $recordTtl === 1 ? 'Auto' : $recordTtl }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            @if($canProxy)
                                                <form action="{{ route('customer.domains.dns.update-record', [$domain, $recordId]) }}" method="POST" class="inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="name" value="{{ $recordName }}">
                                                    <input type="hidden" name="type" value="{{ $recordType }}">
                                                    <input type="hidden" name="content" value="{{ $recordContent }}">
                                                    <input type="hidden" name="ttl" value="{{ $recordTtl }}">
                                                    @if($recordPriority !== null)
                                                        <input type="hidden" name="priority" value="{{ $recordPriority }}">
                                                    @endif
                                                    <input type="hidden" name="proxied" value="{{ $recordProxied ? '0' : '1' }}">
                                                    <button type="submit" class="inline-flex items-center gap-1.5 text-xs font-semibold rounded-full px-2.5 py-1 transition {{ $recordProxied ? 'bg-orange-100 text-orange-800 dark:bg-orange-950/50 dark:text-orange-200' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}" title="{{ $recordProxied ? 'Proxied — click to use DNS only' : 'DNS only — click to proxy' }}">
                                                        <span class="w-2 h-2 rounded-full {{ $recordProxied ? 'bg-orange-500' : 'bg-slate-400' }}"></span>
                                                        {{ $recordProxied ? 'Proxied' : 'DNS only' }}
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-xs text-slate-400">N/A</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm text-right whitespace-nowrap">
                                            <button type="button" @click="editingId = '{{ $recordId }}'" class="text-blue-600 hover:text-blue-700 dark:text-blue-400 font-medium mr-3">Edit</button>
                                            <form action="{{ route('customer.domains.dns.delete-record', [$domain, $recordId]) }}" method="POST" class="inline" onsubmit="return confirm('Delete this DNS record?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-700 dark:text-red-400 font-medium">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <tr class="bg-slate-50 dark:bg-slate-800/40" x-show="editingId === '{{ $recordId }}'" x-cloak>
                                        <td colspan="6" class="px-4 py-4">
                                            <form
                                                action="{{ route('customer.domains.dns.update-record', [$domain, $recordId]) }}"
                                                method="POST"
                                                class="grid grid-cols-1 md:grid-cols-2 gap-3"
                                                x-data="{
                                                    type: '{{ $recordType }}',
                                                    proxied: {{ $recordProxied ? 'true' : 'false' }},
                                                    proxyable: {{ Js::from($proxyableTypes) }},
                                                    canProxy(type) { return this.proxyable.includes(String(type || '').toUpperCase()); }
                                                }"
                                            >
                                                @csrf
                                                @method('PATCH')
                                                <div>
                                                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Name</label>
                                                    <input type="text" name="name" value="{{ $recordName }}" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm text-slate-900 dark:text-white" required>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Type</label>
                                                    <select name="type" x-model="type" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm text-slate-900 dark:text-white" required>
                                                        @foreach (['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'] as $type)
                                                            <option value="{{ $type }}" @selected($recordType === $type)>{{ $type }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="md:col-span-2">
                                                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Content</label>
                                                    <input type="text" name="content" value="{{ $recordContent }}" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm text-slate-900 dark:text-white" required>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">TTL</label>
                                                    <input type="number" name="ttl" value="{{ $recordTtl }}" min="1" max="86400" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm text-slate-900 dark:text-white" :disabled="canProxy(type) && proxied">
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Priority</label>
                                                    <input type="number" name="priority" value="{{ $recordPriority }}" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm text-slate-900 dark:text-white">
                                                </div>
                                                <div class="md:col-span-2" x-show="canProxy(type)" x-cloak>
                                                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                                        <input type="hidden" name="proxied" value="0">
                                                        <input type="checkbox" name="proxied" value="1" class="rounded border-slate-300" x-model="proxied">
                                                        <span>Proxy through Cloudflare</span>
                                                    </label>
                                                </div>
                                                <div class="md:col-span-2 flex items-center gap-3">
                                                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Save changes</button>
                                                    <button type="button" @click="editingId = null" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">No DNS records yet. Add your first record above.</div>
                @endif
            </div>
        </div>
    @elseif(($canProvision ?? false) && ($cloudflareAvailable ?? false))
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 text-center space-y-4">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Enable managed DNS</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400 max-w-lg mx-auto">Provision a Cloudflare DNS zone for this domain so you can manage records from this page.</p>
            <form action="{{ route('customer.domains.dns.provision', $domain) }}" method="POST">
                @csrf
                <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition">Enable DNS Management</button>
            </form>
        </div>
    @else
        <div class="bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl p-6">
            <p class="text-amber-900 dark:text-amber-200">DNS management is not available for this domain yet. Contact support if you need help.</p>
        </div>
    @endif

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
        <h3 class="font-bold text-slate-900 dark:text-white mb-3">Nameservers</h3>
        <div class="text-sm text-slate-600 dark:text-slate-400 space-y-1 font-mono">
            <p>NS1: {{ $domain->nameserver_1 ?? '—' }}</p>
            <p>NS2: {{ $domain->nameserver_2 ?? '—' }}</p>
            @if($domain->nameserver_3)<p>NS3: {{ $domain->nameserver_3 }}</p>@endif
            @if($domain->nameserver_4)<p>NS4: {{ $domain->nameserver_4 }}</p>@endif
        </div>
    </div>
</div>
@endsection
