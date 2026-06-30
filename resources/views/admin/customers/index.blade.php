@extends('layouts.admin')

@section('title', 'Customers')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Customers</p>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Customers</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">
                @if ($usesDirectAdminDirectory ?? false)
                    Platform customers plus DirectAdmin hosted users from connected resellers.
                @else
                    Manage customer accounts and subscriptions.
                @endif
            </p>
        </div>
        <a href="{{ route('admin.customers.create') }}" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Customer
        </a>
    </div>

    @if (! empty($platformRegistrationUrl))
        <div class="bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800/40 rounded-2xl p-5">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-blue-900 dark:text-blue-100">Customer self-registration</h2>
                    <p class="text-xs text-blue-800 dark:text-blue-200 mt-1 max-w-2xl">
                        Share this link so customers can create their own platform account. They must provide a mobile number; verification codes are sent by email and SMS.
                    </p>
                </div>
                <div class="flex flex-col sm:flex-row gap-2 w-full lg:max-w-xl">
                    <input type="text" readonly value="{{ $platformRegistrationUrl }}" class="flex-1 px-3 py-2 text-xs font-mono border border-blue-200 dark:border-blue-700 bg-white dark:bg-slate-900 rounded-lg text-slate-700 dark:text-slate-300">
                    <button type="button" onclick="navigator.clipboard.writeText(@js($platformRegistrationUrl)); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy link', 2000)" class="shrink-0 px-4 py-2 text-xs font-semibold bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">Copy link</button>
                </div>
            </div>
        </div>
    @endif

    @if ($usesDirectAdminDirectory ?? false)
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Total users</p>
                <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">{{ $directoryStats['total'] ?? 0 }}</p>
            </div>
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">On DirectAdmin</p>
                <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">{{ $directoryStats['directadmin_total'] ?? 0 }}</p>
            </div>
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-emerald-200 dark:border-emerald-800 p-4">
                <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Linked</p>
                <p class="text-2xl font-bold text-emerald-700 dark:text-emerald-300 mt-1">{{ $directoryStats['linked'] ?? 0 }}</p>
            </div>
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-amber-200 dark:border-amber-800 p-4">
                <p class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">Unlinked</p>
                <p class="text-2xl font-bold text-amber-700 dark:text-amber-300 mt-1">{{ $directoryStats['unlinked'] ?? 0 }}</p>
            </div>
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-blue-200 dark:border-blue-800 p-4">
                <p class="text-xs uppercase tracking-wide text-blue-700 dark:text-blue-300">Auto-billing ready</p>
                <p class="text-2xl font-bold text-blue-700 dark:text-blue-300 mt-1">{{ $directoryStats['billing_ready'] ?? 0 }}</p>
            </div>
        </div>
    @endif

    <!-- Filters -->
    <form method="GET" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-{{ ($usesDirectAdminDirectory ?? false) ? '7' : '5' }} gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, email, DA username, domain..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <option value="all">All Status</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="suspended" @selected(request('status') === 'suspended')>Suspended</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                    <option value="unverified" @selected(request('status') === 'unverified')>Unverified email</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Account Type</label>
                <select name="type" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <option value="">All Types</option>
                    <option value="individual" @selected(request('type') === 'individual')>Individual</option>
                    <option value="company" @selected(request('type') === 'company')>Company</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Owner</label>
                <select name="owner" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <option value="all" @selected(request('owner', 'all') === 'all')>All owners</option>
                    <option value="platform" @selected(request('owner') === 'platform')>Platform (direct)</option>
                    <option value="reseller" @selected(request('owner') === 'reseller')>Reseller-managed</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Reseller</label>
                <select name="reseller_id" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <option value="">All resellers</option>
                    @foreach ($resellers as $reseller)
                        <option value="{{ $reseller->id }}" @selected((string) request('reseller_id') === (string) $reseller->id)>{{ $reseller->name }}</option>
                    @endforeach
                </select>
            </div>
            @if ($usesDirectAdminDirectory ?? false)
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Platform link</label>
                    <select name="link" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                        <option value="all">All</option>
                        <option value="linked" @selected(request('link') === 'linked')>Linked</option>
                        <option value="unlinked" @selected(request('link') === 'unlinked')>Unlinked</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Billing</label>
                    <select name="billing" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                        <option value="all">All</option>
                        <option value="ready" @selected(request('billing') === 'ready')>Auto-billing ready</option>
                        <option value="package_detected" @selected(request('billing') === 'package_detected')>Package detected</option>
                        <option value="needs_package" @selected(request('billing') === 'needs_package')>Needs package</option>
                    </select>
                </div>
            @endif
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">Filter</button>
            @if ($usesDirectAdminDirectory ?? false)
                <a href="{{ request()->fullUrlWithQuery(['refresh' => 1]) }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-medium rounded-lg transition text-sm">Refresh from DirectAdmin</a>
            @endif
        </div>
    </form>

    <!-- Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Customer</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Owner</th>
                        @if ($usesDirectAdminDirectory ?? false)
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">DA user</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Domain</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Package</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Link</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Billing</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Company</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Country</th>
                        @else
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Company</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Country</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        @endif
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Services</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Invoices</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($customers as $customer)
                        @if ($usesDirectAdminDirectory ?? false)
                            @include('partials.customer-directory-row', ['row' => $customer, 'context' => 'admin'])
                        @else
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-sm font-semibold">
                                        {{ strtoupper(substr($customer->name, 0, 1)) }}
                                    </div>
                                    <div>
                                        <x-admin.customer-link :user="$customer" />
                                        <p class="text-xs text-slate-600 dark:text-slate-400">{{ $customer->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                @if ($customer->reseller_id && $customer->reseller)
                                    <a href="{{ route('admin.resellers.show', $customer->reseller) }}" class="font-medium text-purple-700 dark:text-purple-300 hover:underline text-sm">
                                        {{ $customer->reseller->name }}
                                    </a>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Reseller</p>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300">
                                        Platform
                                    </span>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Direct customer</p>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                {{ $customer->company ?: '-' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                {{ \App\Support\Countries::display($customer->country) }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $customer->status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($customer->status === 'suspended' ? 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400') }}">
                                        {{ ucfirst($customer->status) }}
                                    </span>
                                    @if (!$customer->email_verified_at)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-violet-100 dark:bg-violet-950 text-violet-700 dark:text-violet-300">
                                            Unverified
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-900 dark:text-white font-medium">
                                {{ $customer->services_count }}
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-900 dark:text-white font-medium">
                                {{ $customer->invoices_count }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1" x-data="{
                                    menuOpen: false,
                                    convertModal: false,
                                    transferModal: false,
                                    targetResellerId: '',
                                    transferPreview: null,
                                    previewLoading: false,
                                    previewError: null,
                                    previewUrl: @js(route('admin.customers.transfer-preview', $customer)),
                                    async loadTransferPreview() {
                                        this.previewError = null;
                                        this.transferPreview = null;
                                        if (!this.targetResellerId) return;
                                        this.previewLoading = true;
                                        try {
                                            const res = await fetch(this.previewUrl + '?target_reseller_id=' + encodeURIComponent(this.targetResellerId), {
                                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                                            });
                                            const data = await res.json();
                                            if (!res.ok) {
                                                this.previewError = data.error || 'Could not load preview.';
                                                return;
                                            }
                                            this.transferPreview = data;
                                        } catch (e) {
                                            this.previewError = 'Could not load preview.';
                                        } finally {
                                            this.previewLoading = false;
                                        }
                                    },
                                    openTransferModal() {
                                        this.menuOpen = false;
                                        this.transferModal = true;
                                        this.targetResellerId = '';
                                        this.transferPreview = null;
                                        this.previewError = null;
                                    }
                                }">
                                    <a href="{{ route('admin.customers.show', $customer) }}" class="action-icon-btn text-brand-600 dark:text-brand-400 hover:bg-brand-50 dark:hover:bg-brand-950/40" title="View customer">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </a>
                                    <a href="{{ route('admin.customers.edit', $customer) }}" class="action-icon-btn text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" title="Edit customer">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </a>
                                    <form method="POST" action="{{ route('admin.customers.impersonate', $customer) }}" class="inline-flex shrink-0">
                                        @csrf
                                        <button type="submit" class="action-icon-btn text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/40" title="View as this customer">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                                            </svg>
                                        </button>
                                    </form>

                                    <div class="relative shrink-0">
                                        <button type="button" @click="menuOpen = !menuOpen" class="action-icon-btn text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" title="More actions" aria-label="More actions">
                                            <svg fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                                <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/>
                                            </svg>
                                        </button>
                                        <div x-show="menuOpen" x-cloak @click.outside="menuOpen = false"
                                            class="absolute right-0 mt-1 w-52 bg-white dark:bg-slate-900 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 z-50 py-1 overflow-hidden">
                                            @if(!$customer->is_reseller)
                                                <button type="button" @click="menuOpen = false; convertModal = true" class="w-full text-left px-4 py-2.5 text-sm font-medium text-amber-700 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/40">
                                                    Convert to reseller
                                                </button>
                                            @endif
                                            <button type="button" @click="openTransferModal()" class="w-full text-left px-4 py-2.5 text-sm font-medium text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-950/40">
                                                Transfer to reseller
                                            </button>
                                            <form method="POST" action="{{ route('admin.customers.destroy', $customer) }}" data-confirm="Are you sure you want to delete this customer?">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="w-full text-left px-4 py-2.5 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40">
                                                    Delete customer
                                                </button>
                                            </form>
                                        </div>
                                    </div>

                                    @if(!$customer->is_reseller)
                                        <div x-show="convertModal" x-cloak class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.self="convertModal = false">
                                            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 max-w-sm w-full">
                                                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2">Convert to Reseller?</h3>
                                                <p class="text-slate-600 dark:text-slate-400 mb-6 text-sm">Convert <strong>{{ $customer->name }}</strong> to a reseller account? They will be able to manage their own customers and packages.</p>
                                                <div class="flex gap-3 justify-end">
                                                    <button type="button" @click="convertModal = false" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-medium rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition text-sm">
                                                        Cancel
                                                    </button>
                                                    <form method="POST" action="{{ route('admin.customers.convert-to-reseller', $customer) }}" class="inline">
                                                        @csrf
                                                        <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition text-sm">
                                                            Convert to Reseller
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    <div x-show="transferModal" x-cloak class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.self="transferModal = false">
                                        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 max-w-lg w-full max-h-[90vh] overflow-y-auto">
                                            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2">Transfer to Reseller</h3>
                                            <p class="text-slate-600 dark:text-slate-400 mb-3 text-sm">
                                                Move <strong>{{ $customer->name }}</strong> to a reseller partner. The customer keeps their login; services and domains are reassigned. Open invoices are cancelled so the reseller can start fresh billing.
                                            </p>
                                            @if ($customer->reseller)
                                            <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">Current owner: <strong>{{ $customer->reseller->name }}</strong></p>
                                            @else
                                            <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">Current owner: <strong>Platform (direct)</strong></p>
                                            @endif
                                            <form method="POST" action="{{ route('admin.customers.transfer-to-reseller', $customer) }}">
                                                @csrf
                                                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Destination</label>
                                                <select name="target_reseller_id" x-model="targetResellerId" @change="loadTransferPreview()" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm mb-4" required>
                                                    <option value="">Select destination...</option>
                                                    @if($customer->reseller_id)
                                                        <option value="platform">Platform (direct)</option>
                                                    @endif
                                                    @foreach($resellers->where('id', '!=', $customer->reseller_id) as $reseller)
                                                        <option value="{{ $reseller->id }}">{{ $reseller->name }} ({{ $reseller->email }})</option>
                                                    @endforeach
                                                </select>

                                                <div x-show="previewLoading" class="text-sm text-slate-500 mb-4">Loading preview…</div>
                                                <div x-show="previewError" x-cloak class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-950/40 text-red-700 dark:text-red-300 text-sm" x-text="previewError"></div>

                                                <template x-if="transferPreview">
                                                    <div class="mb-4 space-y-3 text-sm">
                                                        <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                                                            <p class="font-medium text-slate-900 dark:text-white mb-2">What will happen</p>
                                                            <ul class="space-y-1 text-slate-600 dark:text-slate-400 list-disc list-inside">
                                                                <li><span x-text="transferPreview.counts.services"></span> service(s) and <span x-text="transferPreview.counts.domains"></span> domain(s) reassigned</li>
                                                                <li><span x-text="transferPreview.counts.open_tickets"></span> open ticket(s) routed to the new manager</li>
                                                                <template x-if="transferPreview.will_cancel_invoices">
                                                                    <li><span x-text="transferPreview.counts.open_invoices"></span> open invoice(s) will be <strong>cancelled</strong></li>
                                                                </template>
                                                                <template x-if="transferPreview.will_send_customer_email">
                                                                    <li>Customer receives a branded email (no SMS)</li>
                                                                </template>
                                                                <template x-if="transferPreview.counts.da_accounts > 0">
                                                                    <li><span x-text="transferPreview.counts.da_accounts"></span> DirectAdmin hosting account(s) moved on the server</li>
                                                                </template>
                                                            </ul>
                                                        </div>

                                                        <template x-if="transferPreview.open_invoices && transferPreview.open_invoices.length > 0 && transferPreview.will_cancel_invoices">
                                                            <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800">
                                                                <p class="font-medium text-amber-900 dark:text-amber-200 mb-1">Invoices to cancel</p>
                                                                <ul class="text-amber-800 dark:text-amber-300 text-xs space-y-0.5">
                                                                    <template x-for="inv in transferPreview.open_invoices" :key="inv.number">
                                                                        <li x-text="inv.number + ' — ' + inv.status + ' — KES ' + inv.total"></li>
                                                                    </template>
                                                                </ul>
                                                            </div>
                                                        </template>

                                                        <template x-if="transferPreview.service_mappings && transferPreview.service_mappings.length > 0 && targetResellerId !== 'platform'">
                                                            <div class="p-3 rounded-lg bg-purple-50 dark:bg-purple-950/30 border border-purple-200 dark:border-purple-800">
                                                                <p class="font-medium text-purple-900 dark:text-purple-200 mb-1">Service catalog mapping</p>
                                                                <ul class="text-purple-800 dark:text-purple-300 text-xs space-y-1">
                                                                    <template x-for="row in transferPreview.service_mappings" :key="row.service_id">
                                                                        <li>
                                                                            <span x-text="'#' + row.service_id + ' ' + row.service_name"></span>
                                                                            <span class="text-purple-600 dark:text-purple-400"> → </span>
                                                                            <template x-if="row.to_listing">
                                                                                <span x-text="row.to_listing + ' (' + row.match_type + ')'"></span>
                                                                            </template>
                                                                            <template x-if="!row.to_listing">
                                                                                <span class="text-red-600 dark:text-red-400">No matching plan</span>
                                                                            </template>
                                                                        </li>
                                                                    </template>
                                                                </ul>
                                                            </div>
                                                        </template>

                                                        <template x-if="transferPreview.warnings && transferPreview.warnings.length > 0">
                                                            <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800">
                                                                <p class="font-medium text-amber-900 dark:text-amber-200 mb-1">Warnings</p>
                                                                <ul class="text-amber-800 dark:text-amber-300 text-xs space-y-0.5 list-disc list-inside">
                                                                    <template x-for="(w, i) in transferPreview.warnings" :key="i">
                                                                        <li x-text="w"></li>
                                                                    </template>
                                                                </ul>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>

                                                <div class="flex gap-3 justify-end">
                                                    <button type="button" @click="transferModal = false" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-medium rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition text-sm">
                                                        Cancel
                                                    </button>
                                                    <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition text-sm" :disabled="!targetResellerId">
                                                        Confirm transfer
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ ($usesDirectAdminDirectory ?? false) ? 13 : 8 }}" class="px-6 py-12 text-center">
                                <p class="text-slate-600 dark:text-slate-400">No customers found.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        {{ $customers->links() }}
    </div>
</div>
@endsection
