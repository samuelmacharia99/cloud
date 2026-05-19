@extends('layouts.customer')

@section('title', $domain->name . $domain->extension . ' - Nameservers')

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
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Nameservers</p>
</div>
@endsection

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Manage Nameservers</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">{{ $domain->name }}{{ $domain->extension }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Update Form -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-6">Update Nameservers</h2>

                <form action="{{ route('customer.domains.dns.update-nameservers', $domain) }}" method="POST" class="space-y-4">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Primary Nameserver</label>
                        <input type="text" name="nameserver_1" value="{{ $domain->nameserver_1 ?? '' }}" placeholder="e.g., ns1.example.com" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" required>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Required</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Secondary Nameserver</label>
                        <input type="text" name="nameserver_2" value="{{ $domain->nameserver_2 ?? '' }}" placeholder="e.g., ns2.example.com" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Optional</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Tertiary Nameserver</label>
                        <input type="text" name="nameserver_3" value="{{ $domain->nameserver_3 ?? '' }}" placeholder="e.g., ns3.example.com" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Optional</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Quaternary Nameserver</label>
                        <input type="text" name="nameserver_4" value="{{ $domain->nameserver_4 ?? '' }}" placeholder="e.g., ns4.example.com" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Optional</p>
                    </div>

                    <div class="bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                        <p class="text-sm text-amber-900 dark:text-amber-200">
                            <strong>Important:</strong> Changes may take up to 48 hours to propagate globally. Your domain will remain active during this period.
                        </p>
                    </div>

                    <button type="submit" class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition">
                        Update Nameservers
                    </button>
                </form>
            </div>

            <!-- Current Nameservers -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Current Nameservers</h2>

                <div class="space-y-3">
                    <div class="p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Primary</p>
                        <p class="text-slate-900 dark:text-white font-medium">{{ $domain->nameserver_1 ?? 'Not configured' }}</p>
                    </div>
                    <div class="p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Secondary</p>
                        <p class="text-slate-900 dark:text-white font-medium">{{ $domain->nameserver_2 ?? 'Not configured' }}</p>
                    </div>
                    @if($domain->nameserver_3)
                    <div class="p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Tertiary</p>
                        <p class="text-slate-900 dark:text-white font-medium">{{ $domain->nameserver_3 }}</p>
                    </div>
                    @endif
                    @if($domain->nameserver_4)
                    <div class="p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Quaternary</p>
                        <p class="text-slate-900 dark:text-white font-medium">{{ $domain->nameserver_4 }}</p>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Help Box -->
            <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6">
                <h3 class="font-bold text-blue-900 dark:text-blue-200 mb-3">What are Nameservers?</h3>
                <p class="text-sm text-blue-800 dark:text-blue-300 mb-4">
                    Nameservers tell the internet where your domain is hosted. They translate your domain name into an IP address.
                </p>
                <a href="{{ route('customer.domains.dns.index', $domain) }}" class="inline-block px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">
                    Go to DNS Records
                </a>
            </div>

            <!-- Common Providers -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="font-bold text-slate-900 dark:text-white mb-3">Common Nameservers</h3>
                <div class="space-y-2 text-xs">
                    <div class="p-2 bg-slate-50 dark:bg-slate-800 rounded">
                        <p class="font-medium text-slate-900 dark:text-white">Talksasa</p>
                        <p class="text-slate-500 dark:text-slate-400">ns1.talksasa.com</p>
                    </div>
                    <div class="p-2 bg-slate-50 dark:bg-slate-800 rounded">
                        <p class="font-medium text-slate-900 dark:text-white">Cloudflare</p>
                        <p class="text-slate-500 dark:text-slate-400">ns1.cloudflare.com</p>
                    </div>
                    <div class="p-2 bg-slate-50 dark:bg-slate-800 rounded">
                        <p class="font-medium text-slate-900 dark:text-white">Google Domains</p>
                        <p class="text-slate-500 dark:text-slate-400">ns1.google.com</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
