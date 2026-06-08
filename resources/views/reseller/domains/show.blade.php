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
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-500">Status</p>
            <div class="mt-2"><x-domain-status-badge :status="$domain->status" /></div>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-500">Registered</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white mt-2">{{ $domain->registered_at?->format('M d, Y') ?? '—' }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
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

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="font-semibold text-slate-900 dark:text-white mb-4">Nameservers</h2>
            <form method="POST" action="{{ route('reseller.domains.nameservers', $domain) }}" class="space-y-3">
                @csrf
                @method('PUT')
                @foreach(['nameserver_1' => 'Primary', 'nameserver_2' => 'Secondary', 'nameserver_3' => 'Tertiary', 'nameserver_4' => 'Quaternary'] as $field => $label)
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">{{ $label }}</label>
                        <input type="text" name="{{ $field }}" value="{{ old($field, $domain->{$field}) }}"
                            class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600"
                            @if($field === 'nameserver_1') required @endif>
                    </div>
                @endforeach
                <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium">Save nameservers</button>
            </form>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="font-semibold text-slate-900 dark:text-white mb-4">Transfer to another customer</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Moves ownership between your managed customers after the recipient approves.</p>
            @if($transferTargets->isEmpty())
                <p class="text-sm text-slate-500">Add another customer to enable transfers.</p>
            @else
                <form method="POST" action="{{ route('reseller.domains.transfer', $domain) }}" class="space-y-3" data-confirm="Send transfer request to the selected customer?">
                    @csrf
                    <select name="to_customer_id" required class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600">
                        <option value="">Select customer…</option>
                        @foreach($transferTargets as $customer)
                            <option value="{{ $customer->id }}" @selected(old('to_customer_id') == $customer->id)>{{ $customer->name }} ({{ $customer->email }})</option>
                        @endforeach
                    </select>
                    <button type="submit" class="px-4 py-2 border border-purple-300 text-purple-700 dark:text-purple-300 rounded-lg text-sm font-medium" @disabled($domain->pending_transfer_to_user_id)>
                        Request transfer
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <h2 class="font-semibold text-slate-900 dark:text-white mb-4">DNS records</h2>
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
