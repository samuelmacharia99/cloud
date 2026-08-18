@extends('layouts.reseller')

@section('title', $domain->fqdn())

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('reseller.domains.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Domains</a>
    <span class="text-slate-400">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium font-mono">{{ $domain->fqdn() }}</p>
</div>
@endsection

@php
    $owner = $domain->user;
    $ownedByReseller = $owner && (int) $owner->id === (int) auth()->id();
    $managedCustomer = $owner && ! $ownedByReseller && (int) $owner->reseller_id === (int) auth()->id();
    $isLocked = (bool) ($registry['locked'] ?? $domain->registry_locked);
    $privacyOn = (bool) ($registry['whois_privacy'] ?? $domain->whois_privacy);
    $dnsOnly = $domain->isDnsManaged();

    $allowedTabs = ['registry', 'whois', 'ownership', 'dns'];
    $tab = request('tab', 'registry');
    if ($errors->has('to_customer_id')) {
        $tab = 'ownership';
    } elseif (collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'registrant.'))) {
        $tab = 'whois';
    } elseif ($errors->has('confirm_unlock') || $errors->has('nameserver_1') || $errors->has('nameserver_2')) {
        $tab = 'registry';
    }
    if (! in_array($tab, $allowedTabs, true) || ($dnsOnly && in_array($tab, ['registry', 'whois'], true))) {
        $tab = $dnsOnly ? 'dns' : 'registry';
    }
@endphp

@section('content')
<div class="space-y-6 max-w-5xl" x-data="{
    tab: @js($tab),
    renewYears: '1',
    renewing: false,
    setTab(name) {
        this.tab = name;
        const url = new URL(window.location.href);
        url.searchParams.set('tab', name);
        history.replaceState(null, '', url.toString());
    }
}">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white font-mono break-all">{{ $domain->fqdn() }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-600 dark:text-slate-400">
                @if($ownedByReseller)
                    <span>On your reseller account</span>
                @elseif($owner)
                    <span>
                        Customer:
                        @if($managedCustomer)
                            <a href="{{ route('reseller.customers.show', $owner) }}" class="font-medium text-purple-700 dark:text-purple-300 hover:underline">{{ $owner->name }}</a>
                        @else
                            <span class="font-medium text-slate-900 dark:text-white">{{ $owner->name }}</span>
                        @endif
                        <span class="text-slate-500">({{ $owner->email }})</span>
                    </span>
                @else
                    <span>Owner not linked</span>
                @endif
                @unless($dnsOnly)
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <span>{{ $isLocked ? 'Locked' : 'Unlocked' }}</span>
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <span>{{ $privacyOn ? 'WHOIS privacy on' : 'WHOIS public' }}</span>
                @endunless
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('reseller.domains.index') }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-800">Domains</a>
            @if($managedCustomer)
                <form method="POST" action="{{ route('reseller.customers.impersonate', $owner) }}" class="inline">
                    @csrf
                    <button type="submit" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-800">View as customer</button>
                </form>
            @endif
            @unless($dnsOnly)
                <div class="flex items-center gap-2">
                    <label class="sr-only" for="renew-years">Renewal years</label>
                    <select id="renew-years" x-model="renewYears" class="px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600">
                        @foreach([1, 2, 3, 5, 10] as $period)
                            <option value="{{ $period }}">{{ $period }} year{{ $period > 1 ? 's' : '' }}</option>
                        @endforeach
                    </select>
                    <button type="button"
                        @click="async () => {
                            renewing = true;
                            try {
                                const res = await fetch(@js(route('reseller.domains.renew', $domain)), {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                                    },
                                    body: JSON.stringify({ years: parseInt(renewYears, 10) })
                                });
                                const data = await res.json();
                                if (data.success) {
                                    window.location.href = data.redirect || @js(route('reseller.cart.index'));
                                } else {
                                    alert(data.message || 'Could not add renewal to cart');
                                }
                            } catch (e) {
                                alert('Could not add renewal to cart.');
                            } finally {
                                renewing = false;
                            }
                        }"
                        :disabled="renewing"
                        class="px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white rounded-lg text-sm font-medium">
                        <span x-show="!renewing">Renew</span>
                        <span x-show="renewing" x-cloak>Adding…</span>
                    </button>
                </div>
            @endunless
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="ui-card p-5">
            <p class="text-sm text-slate-500">Status</p>
            <div class="mt-2"><x-domain-status-badge :status="$domain->status" /></div>
        </div>
        <div class="ui-card p-5">
            <p class="text-sm text-slate-500">Expires</p>
            <p class="text-lg font-semibold mt-2 {{ $domain->isExpired() ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">
                {{ $domain->expires_at?->format('M d, Y') ?? '—' }}
            </p>
            @if($domain->expires_at)
                <p class="text-xs mt-1 {{ $domain->isExpired() ? 'text-red-600 dark:text-red-400' : 'text-slate-500' }}">
                    {{ $domain->daysUntilExpiry() }} days {{ $domain->isExpired() ? 'overdue' : 'remaining' }}
                </p>
            @endif
        </div>
        <div class="ui-card p-5">
            <p class="text-sm text-slate-500">Auto-renew</p>
            @unless($dnsOnly)
                <form method="POST" action="{{ route('reseller.domains.auto-renew', $domain) }}" class="mt-2">
                    @csrf
                    <input type="hidden" name="auto_renew" value="{{ $domain->auto_renew ? 0 : 1 }}">
                    <button type="submit" class="inline-flex items-center gap-2 text-left">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {{ $domain->auto_renew ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">
                            {{ $domain->auto_renew ? 'On' : 'Off' }}
                        </span>
                        <span class="text-sm text-purple-700 dark:text-purple-300 hover:underline">{{ $domain->auto_renew ? 'Turn off' : 'Turn on' }}</span>
                    </button>
                </form>
                <p class="text-xs text-slate-500 mt-2">Uses prepaid balance at expiry.</p>
            @else
                <p class="mt-2 text-sm text-slate-500">Not used for DNS-only names.</p>
            @endunless
        </div>
    </div>

    @if($domain->pending_transfer_to_user_id)
        <div class="p-4 rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-800 text-sm text-amber-900 dark:text-amber-200">
            Transfer pending approval from <strong>{{ $domain->pendingTransferRecipient?->name ?? 'recipient' }}</strong>
            @if($domain->transfer_requested_at)
                since {{ $domain->transfer_requested_at->format('M d, Y H:i') }}.
            @endif
        </div>
    @endif

    @if(! empty($registry['message']))
        <div class="p-4 rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-800 text-sm text-amber-900 dark:text-amber-200">
            {{ str_ireplace('Cosmotown', 'the registry', $registry['message']) }}
        </div>
    @endif

    @if($domain->isExpired() && ! $dnsOnly)
        <div class="p-4 rounded-xl border border-red-200 bg-red-50 dark:bg-red-950/30 dark:border-red-800 text-sm text-red-900 dark:text-red-200">
            This domain is expired at the registry. Renew it to restore the registration. The registry does not offer a separate restore button here.
        </div>
    @endif

    <div class="ui-card overflow-hidden">
        <div class="border-b border-slate-200 dark:border-slate-800 px-2 sm:px-4">
            <nav class="flex gap-1 overflow-x-auto" aria-label="Domain sections">
                @unless($dnsOnly)
                    <button type="button" @click="setTab('registry')" :class="tab === 'registry' ? 'border-purple-600 text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'" class="shrink-0 px-4 py-3 text-sm font-medium border-b-2">
                        Registry
                    </button>
                    <button type="button" @click="setTab('whois')" :class="tab === 'whois' ? 'border-purple-600 text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'" class="shrink-0 px-4 py-3 text-sm font-medium border-b-2">
                        WHOIS
                    </button>
                @endunless
                <button type="button" @click="setTab('ownership')" :class="tab === 'ownership' ? 'border-purple-600 text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'" class="shrink-0 px-4 py-3 text-sm font-medium border-b-2">
                    Ownership
                </button>
                <button type="button" @click="setTab('dns')" :class="tab === 'dns' ? 'border-purple-600 text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'" class="shrink-0 px-4 py-3 text-sm font-medium border-b-2">
                    DNS snapshot
                </button>
            </nav>
        </div>

        @unless($dnsOnly)
            <div x-show="tab === 'registry'" class="p-6 space-y-8">
                <div>
                    <div class="flex items-start justify-between gap-3 mb-1">
                        <h2 class="text-lg font-bold text-slate-900 dark:text-white">Nameservers</h2>
                        @if(! empty($registry['nameservers_live']))
                            <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">Live from registry</span>
                        @endif
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                        At least two unique nameservers are required. Saving publishes them at the registry. DNS can take up to 48 hours to follow.
                    </p>
                    <form method="POST" action="{{ route('reseller.domains.nameservers', $domain) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @csrf
                        @method('PUT')
                        @foreach(['nameserver_1' => 'Nameserver 1', 'nameserver_2' => 'Nameserver 2', 'nameserver_3' => 'Nameserver 3', 'nameserver_4' => 'Nameserver 4'] as $field => $label)
                            <div>
                                <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">{{ $label }}</label>
                                <input type="text" name="{{ $field }}" value="{{ old($field, $nameservers[$field] ?? $domain->{$field}) }}"
                                    class="w-full px-3 py-2 text-sm font-mono border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600"
                                    @if(in_array($field, ['nameserver_1', 'nameserver_2'], true)) required @endif>
                                @error($field)<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                        <div class="md:col-span-2">
                            <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium">Save nameservers</button>
                        </div>
                    </form>
                </div>

                <hr class="border-slate-200 dark:border-slate-700">

                @include('domains.partials.registry-management', [
                    'audience' => 'reseller',
                    'showEpp' => true,
                    'showRegistryAlerts' => false,
                    'registrantRoute' => null,
                    'optionsRoute' => route('reseller.domains.registry-options', $domain),
                ])
            </div>

            <div x-show="tab === 'whois'" class="p-6">
                @include('domains.partials.registry-management', [
                    'audience' => 'reseller',
                    'showEpp' => false,
                    'showRegistryAlerts' => false,
                    'optionsRoute' => null,
                    'registrantRoute' => route('reseller.domains.registrant', $domain),
                    'saveButtonClass' => 'px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-lg',
                ])
            </div>
        @endunless

        <div x-show="tab === 'ownership'" @if($dnsOnly) x-cloak @endif class="p-6 space-y-6">
            <div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-1">Transfer to another customer</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                    Moves this domain to another customer you manage immediately. No recipient approval. Registry registration is unchanged.
                </p>
                @if($transferTargets->isEmpty())
                    <p class="text-sm text-slate-500">Add another customer first, then you can move this domain to them.</p>
                @else
                    <form method="POST" action="{{ route('reseller.domains.transfer', $domain) }}" class="flex flex-col sm:flex-row gap-3 sm:items-end" data-confirm="Transfer this domain to the selected customer now?" data-confirm-title="Transfer domain">
                        @csrf
                        <div class="flex-1 min-w-0">
                            <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">Customer</label>
                            <select name="to_customer_id" required class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600">
                                <option value="">Select…</option>
                                @foreach($transferTargets as $customer)
                                    <option value="{{ $customer->id }}" @selected(old('to_customer_id') == $customer->id)>{{ $customer->name }} ({{ $customer->email }})</option>
                                @endforeach
                            </select>
                            @error('to_customer_id')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium">Transfer domain</button>
                    </form>
                @endif
            </div>

            <hr class="border-slate-200 dark:border-slate-700">

            <div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-1">Remove from account</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                    Deletes the local record only. It does not cancel registration at the registry.
                </p>
                <form method="POST" action="{{ route('reseller.domains.destroy', $domain) }}" data-confirm="Remove this domain from your account? This does not cancel registry registration.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg text-sm font-medium">Remove domain</button>
                </form>
            </div>
        </div>

        <div x-show="tab === 'dns'" class="p-6">
            <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-1">DNS records</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                Read-only snapshot stored on this platform.
                @if($managedCustomer)
                    To edit DNS, <span class="font-medium">view as the customer</span> and use their DNS page.
                @else
                    Edit DNS from the customer portal or your DNS provider panel.
                @endif
            </p>
            @if($dnsRecords->isEmpty())
                <p class="text-sm text-slate-500">No DNS zone records stored locally for this domain.</p>
            @else
                <div class="ui-table-wrap">
                    <table class="ui-table text-sm">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Value</th>
                                <th>TTL</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($dnsRecords as $record)
                                <tr>
                                    <td class="font-mono">{{ $record->name }}</td>
                                    <td>{{ $record->type }}</td>
                                    <td class="font-mono break-all">{{ $record->value }}</td>
                                    <td>{{ $record->ttl }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
