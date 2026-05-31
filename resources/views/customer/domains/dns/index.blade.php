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
    <a href="javascript:void(0)" class="text-sm font-medium text-slate-600 dark:text-slate-400">
        {{ $domain->name }}{{ $domain->extension }}
    </a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">DNS Management</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">DNS Management</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">{{ $domain->name }}{{ $domain->extension }}</p>
        </div>
        <a href="{{ route('customer.domains.dns.nameservers', $domain) }}" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition inline-block">
            Manage Nameservers
        </a>
    </div>

    <!-- Tabs -->
    <div x-data="{ tab: 'records' }" class="space-y-6">
        <div class="flex gap-4 border-b border-slate-200 dark:border-slate-700">
            <button @click="tab = 'records'" :class="tab === 'records' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-3 font-medium transition">
                DNS Records
            </button>
            <button @click="tab = 'info'" :class="tab === 'info' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-3 font-medium transition">
                Nameserver Info
            </button>
        </div>

        <!-- DNS Records Tab -->
        <div x-show="tab === 'records'" class="space-y-6">
            @if($zone)
                <!-- Add Record Form -->
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Add DNS Record</h2>

                    <form action="{{ route('customer.domains.dns.add-record', $domain) }}" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @csrf

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Name/Host</label>
                            <input type="text" name="name" placeholder="e.g., @ or subdomain" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" required>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Type</label>
                            <select name="type" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" required>
                                <option value="A">A</option>
                                <option value="AAAA">AAAA</option>
                                <option value="CNAME">CNAME</option>
                                <option value="MX">MX</option>
                                <option value="TXT">TXT</option>
                                <option value="NS">NS</option>
                                <option value="SRV">SRV</option>
                                <option value="CAA">CAA</option>
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Content/Value</label>
                            <input type="text" name="content" placeholder="e.g., 192.0.2.1 or example.com" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" required>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">TTL (seconds)</label>
                            <input type="number" name="ttl" placeholder="3600" min="300" max="86400" value="3600" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Priority (MX/SRV only)</label>
                            <input type="number" name="priority" placeholder="Optional" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        </div>

                        <div class="md:col-span-2">
                            <button type="submit" class="w-full px-6 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition">
                                Add Record
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Current Records -->
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                        <h2 class="text-lg font-bold text-slate-900 dark:text-white">Current DNS Records</h2>
                    </div>

                    @if($records->count() > 0)
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Type</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Content</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">TTL</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                    @foreach($records as $record)
                                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">{{ $record->name ?? '@' }}</td>
                                            <td class="px-6 py-4 text-sm">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300">
                                                    {{ $record->type }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300 break-all">{{ $record->content }}</td>
                                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">{{ $record->ttl ?? 'N/A' }}</td>
                                            <td class="px-6 py-4 text-sm space-x-2">
                                                <form action="{{ route('customer.domains.dns.delete-record', [$domain, $record]) }}" method="POST" style="display: inline;">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 font-medium">
                                                        Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">
                            <p>No DNS records configured yet</p>
                        </div>
                    @endif
                </div>
            @else
                <div class="bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl p-6">
                    <p class="text-amber-900 dark:text-amber-200">
                        No DNS zone configured for this domain. Please contact support to enable DNS management.
                    </p>
                </div>
            @endif
        </div>

        <!-- Nameserver Info Tab -->
        <div x-show="tab === 'info'" class="space-y-6">
            <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6">
                <h3 class="font-bold text-blue-900 dark:text-blue-200 mb-3">Nameserver Information</h3>
                <div class="space-y-2 text-sm text-blue-800 dark:text-blue-300">
                    <p><strong>Primary Nameserver:</strong> {{ $domain->nameserver_1 ?? 'Not configured' }}</p>
                    <p><strong>Secondary Nameserver:</strong> {{ $domain->nameserver_2 ?? 'Not configured' }}</p>
                    <p class="mt-4">To change nameservers, click the <strong>"Manage Nameservers"</strong> button above.</p>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="font-bold text-slate-900 dark:text-white mb-4">How to Use DNS Records</h3>
                <div class="space-y-4 text-sm text-slate-600 dark:text-slate-400">
                    <div>
                        <p class="font-semibold text-slate-900 dark:text-white mb-1">A Record</p>
                        <p>Points your domain to an IPv4 address (e.g., 192.0.2.1)</p>
                    </div>
                    <div>
                        <p class="font-semibold text-slate-900 dark:text-white mb-1">CNAME Record</p>
                        <p>Creates an alias to another domain name</p>
                    </div>
                    <div>
                        <p class="font-semibold text-slate-900 dark:text-white mb-1">MX Record</p>
                        <p>Directs email to your mail server (requires priority)</p>
                    </div>
                    <div>
                        <p class="font-semibold text-slate-900 dark:text-white mb-1">TXT Record</p>
                        <p>Stores text information (used for SPF, DKIM, domain verification)</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
