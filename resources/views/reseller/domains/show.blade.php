@extends('layouts.reseller')

@section('title', $domain->name.$domain->extension)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('reseller.domains.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Domains</a>
    <span class="text-slate-400">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">{{ $domain->name }}{{ $domain->extension }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6 max-w-5xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white font-mono">{{ $domain->name }}{{ $domain->extension }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">
                Owner: {{ $domain->user?->name ?? '—' }}
                @if($domain->user?->email)
                    <span class="text-slate-500">({{ $domain->user->email }})</span>
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('reseller.domains.index') }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm font-medium">← Back</a>
            <form method="POST" action="{{ route('reseller.domains.destroy', $domain) }}" data-confirm="Remove this domain from your account? This does not cancel registry registration.">
                @csrf
                @method('DELETE')
                <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">Delete</button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="ui-card p-6">
            <p class="text-sm text-slate-500">Status</p>
            <div class="mt-2"><x-domain-status-badge :status="$domain->status" /></div>
        </div>
        <div class="ui-card p-6">
            <p class="text-sm text-slate-500">Registered</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white mt-2">{{ $domain->registered_at?->format('M d, Y') ?? '—' }}</p>
        </div>
        <div class="ui-card p-6">
            <p class="text-sm text-slate-500">Expires</p>
            <p class="text-lg font-semibold mt-2 {{ $domain->isExpired() ? 'text-red-600' : 'text-slate-900 dark:text-white' }}">
                {{ $domain->expires_at?->format('M d, Y') ?? '—' }}
            </p>
            @if($domain->expires_at)
                <p class="text-xs text-slate-500 mt-1">{{ $domain->daysUntilExpiry() }} days {{ $domain->isExpired() ? 'overdue' : 'remaining' }}</p>
            @endif
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
            {{ $registry['message'] }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="ui-card p-6">
            <div class="flex items-start justify-between gap-3 mb-2">
                <h2 class="font-semibold text-slate-900 dark:text-white">Nameservers</h2>
                @if(! empty($registry['nameservers_live']))
                    <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">Live from registry</span>
                @endif
            </div>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                Values below are the nameservers currently published at the registry. Saving updates the registry. DNS changes can take up to 48 hours.
            </p>
            <form method="POST" action="{{ route('reseller.domains.nameservers', $domain) }}" class="space-y-3">
                @csrf
                @method('PUT')
                @foreach(['nameserver_1' => 'Primary', 'nameserver_2' => 'Secondary', 'nameserver_3' => 'Tertiary', 'nameserver_4' => 'Quaternary'] as $field => $label)
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">{{ $label }}</label>
                        <input type="text" name="{{ $field }}" value="{{ old($field, $nameservers[$field] ?? $domain->{$field}) }}"
                            class="w-full px-3 py-2 text-sm font-mono border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600"
                            @if($field === 'nameserver_1') required @endif>
                    </div>
                @endforeach
                <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium">Save nameservers</button>
            </form>
        </div>

        <div class="space-y-6">
            <div class="ui-card p-6" x-data="{ revealed: false, copied: false }">
                <h2 class="font-semibold text-slate-900 dark:text-white mb-2">EPP / auth code</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                    Use this code to transfer the domain to another registrar. Anyone with it can start a transfer — keep it private.
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
                                class="px-3 py-2 text-sm font-medium rounded-lg bg-purple-600 hover:bg-purple-700 text-white">
                                <span x-text="copied ? 'Copied' : 'Copy'"></span>
                            </button>
                        </div>
                    </div>
                    @if(! empty($registry['epp_live']))
                        <p class="text-xs text-emerald-700 dark:text-emerald-300 mt-2">Loaded live from the registry.</p>
                    @else
                        <p class="text-xs text-slate-500 mt-2">Last saved authorization code for this domain.</p>
                    @endif
                @else
                    <p class="text-sm text-slate-500">An EPP / auth code appears here after the domain is registered at the registry.</p>
                @endif
            </div>

            <div class="ui-card p-6">
                <h2 class="font-semibold text-slate-900 dark:text-white mb-4">Transfer to another customer</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Immediately moves ownership to another customer you manage. No recipient approval required.</p>
            @if($transferTargets->isEmpty())
                <p class="text-sm text-slate-500">Add another customer to enable transfers.</p>
            @else
                <form method="POST" action="{{ route('reseller.domains.transfer', $domain) }}" class="space-y-3" data-confirm="Transfer this domain to the selected customer now?" data-confirm-title="Transfer domain">
                    @csrf
                    <select name="to_customer_id" required class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600">
                        <option value="">Select customer…</option>
                        @foreach($transferTargets as $customer)
                            <option value="{{ $customer->id }}" @selected(old('to_customer_id') == $customer->id)>{{ $customer->name }} ({{ $customer->email }})</option>
                        @endforeach
                    </select>
                    <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium">
                        Transfer domain
                    </button>
                </form>
            @endif
        </div>
        </div>
    </div>

    <div class="ui-card p-6">
        <h2 class="font-semibold text-slate-900 dark:text-white mb-4">DNS records</h2>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
            This is a read-only snapshot of locally stored DNS records. To edit DNS, impersonate the customer and manage records in their portal, or use your DirectAdmin / DNS provider panel.
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
@endsection
