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
<div class="space-y-6 max-w-4xl"
     x-data="{
        transferModal: {{ $errors->hasAny(['target_user_id', 'reason', 'confirmation_email', 'transfer_services']) ? 'true' : 'false' }},
        transferPreviewLoading: false,
        transferPreview: null,
        transferTargetId: '{{ old('target_user_id', '') }}',
        customerEmails: @js($transferCustomers->pluck('email', 'id')),
        async loadTransferPreview() {
            if (!this.transferTargetId) {
                this.transferPreview = null;
                return;
            }
            this.transferPreviewLoading = true;
            try {
                const url = new URL(@js(route('admin.domains.transfer-preview', $domain)));
                url.searchParams.set('target_user_id', this.transferTargetId);
                const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const data = await response.json();
                this.transferPreview = response.ok ? data : { error: data.error || 'Preview failed.' };
            } catch (error) {
                this.transferPreview = { error: 'Network error: ' + error.message };
            } finally {
                this.transferPreviewLoading = false;
            }
        }
     }"
     x-init="if (transferTargetId) loadTransferPreview()">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $domain->name }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Domain details and management</p>
        </div>
        <div class="flex gap-3">
            <form action="{{ route('admin.domains.generate-invoice', $domain) }}" method="POST" class="inline">
                @csrf
                <button type="submit" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg transition">
                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Generate Invoice
                </button>
            </form>
            <a href="{{ route('admin.domains.edit', $domain) }}" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
                Edit
            </a>
            <form action="{{ route('admin.domains.destroy', $domain) }}" method="POST" data-confirm='Are you sure you want to delete this domain? This action cannot be undone.'>
                @csrf
                @method('DELETE')
                <button type="submit" class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition">
                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    Delete
                </button>
            </form>
        </div>
    </div>

    <!-- Status Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="ui-card p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Status</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white mt-2">{{ ucfirst($domain->status) }}</p>
            <span class="inline-block mt-3 px-3 py-1 {{ $domain->status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($domain->status === 'expired' ? 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300' : 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300') }} rounded-full text-xs font-medium">
                {{ ucfirst($domain->status) }}
            </span>
        </div>

        <div class="ui-card p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Registered</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white mt-2">{{ $domain->registered_at ? $domain->registered_at->format('M d, Y') : '—' }}</p>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">{{ $domain->registered_at ? $domain->registered_at->diffForHumans() : 'Unknown' }}</p>
        </div>

        <div class="ui-card p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Expires</p>
            @if ($domain->expires_at)
                <p class="text-2xl font-bold {{ $domain->isExpired() ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }} mt-2">
                    {{ $domain->expires_at->format('M d, Y') }}
                </p>
                <p class="text-xs {{ $domain->isExpired() ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }} mt-2">
                    {{ $domain->daysUntilExpiry() }} days {{ $domain->isExpired() ? 'overdue' : 'remaining' }}
                </p>
            @else
                <p class="text-2xl font-bold text-slate-500 dark:text-slate-400 mt-2">—</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Not yet activated</p>
            @endif
        </div>
    </div>

    <!-- Domain Details -->
    <div class="ui-card p-6 space-y-6">
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
                    <div class="mt-1"><x-admin.customer-link :user="$domain->user" class="text-slate-900 dark:text-white" /></div>
                    @if ($domain->user)
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $domain->user->email }}</p>
                    @endif
                    @can('transfer', $domain)
                        <button type="button"
                            @click="transferModal = true; transferPreview = null;"
                            class="mt-2 text-sm font-medium text-violet-700 dark:text-violet-300 hover:underline">
                            Change owner
                        </button>
                    @endcan
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Auto Renewal</p>
                    <p class="text-slate-900 dark:text-white mt-1">{{ $domain->auto_renew ? 'Enabled' : 'Disabled' }}</p>
                </div>
            </div>
        </div>

        <hr class="border-slate-200 dark:border-slate-700">

        @if(! empty($registry['message']))
            <div class="p-4 rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-800 text-sm text-amber-900 dark:text-amber-200">
                {{ $registry['message'] }}
            </div>
        @endif

        <div>
            <div class="flex items-start justify-between gap-3 mb-1">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Nameservers</h2>
                @if(! empty($registry['nameservers_live']))
                    <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">Live from Cosmotown</span>
                @endif
            </div>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                At least two unique nameservers are required. When this domain is linked at the registrar, saving pushes the change there (including Cosmotown). DNS can take up to 48 hours to propagate.
            </p>
            <form method="POST" action="{{ route('admin.domains.nameservers', $domain) }}" class="space-y-4">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach(['nameserver_1' => 'Nameserver 1', 'nameserver_2' => 'Nameserver 2', 'nameserver_3' => 'Nameserver 3', 'nameserver_4' => 'Nameserver 4'] as $field => $label)
                        <div>
                            <label for="{{ $field }}" class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">{{ $label }}</label>
                            <input
                                id="{{ $field }}"
                                type="text"
                                name="{{ $field }}"
                                value="{{ old($field, $nameservers[$field] ?? $domain->{$field}) }}"
                                placeholder="ns1.example.com"
                                class="w-full px-3 py-2 font-mono text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white @error($field) border-red-500 @enderror"
                                @if(in_array($field, ['nameserver_1', 'nameserver_2'], true)) required @endif
                            >
                            @error($field)
                                <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Save nameservers
                </button>
            </form>
        </div>

        <hr class="border-slate-200 dark:border-slate-700">

        <div x-data="{ revealed: false, copied: false }">
            <div class="flex items-start justify-between gap-3 mb-1">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">EPP / auth code</h2>
                @if(! empty($registry['epp_live']))
                    <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">Live from Cosmotown</span>
                @endif
            </div>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                Pulled from Cosmotown when this domain is in that account. Anyone with the code can start a transfer — keep it private.
            </p>
            @if(filled($eppCode))
                <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                    <p class="flex-1 px-3 py-2 font-mono text-sm rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white break-all">
                        <span x-show="!revealed">••••••••••••</span>
                        <span x-show="revealed" x-cloak>{{ $eppCode }}</span>
                    </p>
                    <div class="flex gap-2 shrink-0">
                        <button type="button" @click="revealed = !revealed" class="px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-800">
                            <span x-text="revealed ? 'Hide' : 'Reveal'"></span>
                        </button>
                        <button type="button"
                            @click="navigator.clipboard.writeText(@js($eppCode)); copied = true; setTimeout(() => copied = false, 2000)"
                            class="px-3 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white">
                            <span x-text="copied ? 'Copied' : 'Copy'"></span>
                        </button>
                    </div>
                </div>
                @if(empty($registry['epp_live']))
                    <p class="text-xs text-slate-500 mt-2">Last saved authorization code for this domain.</p>
                @endif
            @else
                <p class="text-sm text-slate-500">
                    @if(! empty($registry['attempted']))
                        Cosmotown did not return an authorization code for this domain. If the domain is locked, unlock it and reload this page.
                    @else
                        An EPP / auth code appears here after the domain is registered at Cosmotown.
                    @endif
                </p>
            @endif
        </div>

        <hr class="border-slate-200 dark:border-slate-700">

        @include('domains.partials.registry-management', [
            'audience' => 'admin',
            'showEpp' => false,
            'registrantRoute' => route('admin.domains.registrant', $domain),
            'optionsRoute' => route('admin.domains.registry-options', $domain),
        ])

        @php
            $domainNotes = $domain->notes;
            $domainNoteEntries = is_array($domainNotes)
                ? $domainNotes
                : (filled($domainNotes) ? [['text' => $domainNotes]] : []);
        @endphp
        @if ($domainNoteEntries !== [])
            <hr class="border-slate-200 dark:border-slate-700">
            <div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Notes</h2>
                <ul class="space-y-2 text-sm text-slate-700 dark:text-slate-300">
                    @foreach ($domainNoteEntries as $note)
                        @if (is_array($note) && ($note['type'] ?? '') === 'admin_ownership_transfer')
                            <li>
                                Ownership moved from {{ $note['from'] ?? '—' }} to {{ $note['to'] ?? '—' }}
                                @if (! empty($note['reason']))
                                    — {{ $note['reason'] }}
                                @endif
                                @if (! empty($note['transferred_at']))
                                    <span class="text-slate-500"> ({{ \Illuminate\Support\Carbon::parse($note['transferred_at'])->format('M d, Y H:i') }})</span>
                                @endif
                            </li>
                        @elseif (is_array($note))
                            <li>{{ $note['text'] ?? $note['to'] ?? json_encode($note) }}</li>
                        @else
                            <li>{{ $note }}</li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <!-- DNS Zones -->
    @if ($domain->dnsZones->count() > 0)
        <div class="ui-card p-6">
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

    @can('transfer', $domain)
    <div x-show="transferModal" x-cloak x-transition
         class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
         @click.self="transferModal = false">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Change domain owner</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                    Moves {{ $domain->fqdn() }} to another customer in Talksasa. Registry registration stays the same.
                </p>
            </div>
            @if ($transferCustomers->isEmpty())
                <div class="p-6">
                    <p class="text-sm text-slate-600 dark:text-slate-400">There is no other customer account to assign this domain to.</p>
                </div>
                <div class="p-6 border-t border-slate-200 dark:border-slate-800">
                    <button type="button" @click="transferModal = false"
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 font-medium">
                        Close
                    </button>
                </div>
            @else
                <form method="POST" action="{{ route('admin.domains.transfer-ownership', $domain) }}">
                    @csrf
                    <div class="p-6 space-y-4">
                        <div>
                            <label for="target_user_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">New owner</label>
                            <select id="target_user_id" name="target_user_id" required x-model="transferTargetId" @change="loadTransferPreview()"
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-violet-500 focus:border-transparent">
                                <option value="">Select a customer…</option>
                                @foreach ($transferCustomers as $customer)
                                    <option value="{{ $customer->id }}" @selected((string) old('target_user_id') === (string) $customer->id)>
                                        {{ $customer->name }} ({{ $customer->email }})
                                    </option>
                                @endforeach
                            </select>
                            @error('target_user_id')
                                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        @if ($linkedServices->isNotEmpty())
                            <fieldset class="rounded-lg border border-slate-200 dark:border-slate-700 p-4 space-y-3">
                                <legend class="text-sm font-medium text-slate-900 dark:text-white px-1">Also move the hosting service?</legend>
                                <p class="text-sm text-slate-600 dark:text-slate-400">
                                    This domain is attached to
                                    {{ $linkedServices->map(fn ($service) => $service->name.' (#'.$service->id.')')->join(', ') }}.
                                    Choose one:
                                </p>
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input type="radio" name="transfer_services" value="1" required
                                           @checked((string) old('transfer_services') === '1')
                                           class="mt-1 border-slate-300 dark:border-slate-600 text-violet-600 focus:ring-violet-500">
                                    <span class="text-sm text-slate-700 dark:text-slate-300">
                                        Yes — move the service{{ $linkedServices->count() > 1 ? 's' : '' }} to the new owner. Related service invoices that only bill {{ $linkedServices->count() > 1 ? 'these services' : 'this service' }} move too.
                                    </span>
                                </label>
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input type="radio" name="transfer_services" value="0" required
                                           @checked((string) old('transfer_services') === '0')
                                           class="mt-1 border-slate-300 dark:border-slate-600 text-violet-600 focus:ring-violet-500">
                                    <span class="text-sm text-slate-700 dark:text-slate-300">
                                        No — leave the service{{ $linkedServices->count() > 1 ? 's' : '' }} on {{ $domain->user?->name ?? 'the current customer' }}.
                                    </span>
                                </label>
                                @error('transfer_services')
                                    <p class="text-red-600 dark:text-red-400 text-xs">{{ $message }}</p>
                                @enderror
                            </fieldset>
                        @endif

                        <div>
                            <label for="reason" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Reason</label>
                            <input type="text" id="reason" name="reason" value="{{ old('reason') }}" required maxlength="500"
                                   placeholder="e.g. Customer asked to move this domain to their other account"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-violet-500 focus:border-transparent">
                            @error('reason')
                                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="confirmation_email" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Confirm new owner email</label>
                            <input type="email" id="confirmation_email" name="confirmation_email" value="{{ old('confirmation_email') }}" required
                                   :placeholder="customerEmails[transferTargetId] || 'Select a customer first'"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-violet-500 focus:border-transparent">
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Type the selected customer’s email to confirm. This cannot be undone from here.</p>
                            @error('confirmation_email')
                                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div x-show="transferPreviewLoading" class="text-sm text-slate-500 dark:text-slate-400">Loading preview…</div>

                        <div x-show="transferPreview?.error" class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-4 py-3 text-sm text-red-800 dark:text-red-200" x-text="transferPreview?.error"></div>

                        <div x-show="transferPreview && !transferPreview.error" class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 px-4 py-3 space-y-2 text-sm">
                            <p class="text-slate-900 dark:text-white">
                                <span class="font-medium">From:</span>
                                <span x-text="transferPreview?.from?.name"></span>
                                <span class="text-slate-500 dark:text-slate-400" x-show="transferPreview?.from?.reseller" x-text="'(' + transferPreview?.from?.reseller + ')'"></span>
                            </p>
                            <p class="text-slate-900 dark:text-white">
                                <span class="font-medium">To:</span>
                                <span x-text="transferPreview?.to?.name"></span>
                                <span class="text-slate-500 dark:text-slate-400" x-show="transferPreview?.to?.reseller" x-text="'(' + transferPreview?.to?.reseller + ')'"></span>
                            </p>
                            <template x-if="transferPreview?.warnings?.length">
                                <ul class="list-disc list-inside text-amber-700 dark:text-amber-300 space-y-1 pt-1">
                                    <template x-for="warning in transferPreview.warnings" :key="warning">
                                        <li x-text="warning"></li>
                                    </template>
                                </ul>
                            </template>
                        </div>
                    </div>
                    <div class="p-6 border-t border-slate-200 dark:border-slate-800 flex gap-3">
                        <button type="button" @click="transferModal = false"
                                class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 font-medium">
                            Cancel
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg font-medium"
                                :disabled="!transferTargetId">
                            Move ownership
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
    @endcan
</div>
@endsection
