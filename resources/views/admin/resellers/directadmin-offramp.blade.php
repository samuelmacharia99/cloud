@extends('layouts.admin')

@section('title', 'DirectAdmin off-ramp: '.$reseller->name)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.resellers.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Resellers</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="{{ route('admin.resellers.show', $reseller) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $reseller->name }}</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">DirectAdmin off-ramp</p>
</div>
@endsection

@section('content')
<div class="space-y-6" x-data="{ selected: {!! json_encode($accounts->mapWithKeys(fn ($account) => [$account['key'] => false])->all()) !!} }">
    <div class="ui-card p-6">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">DirectAdmin off-ramp</h1>
                <p class="text-slate-600 dark:text-slate-400 mt-1">
                    Convert {{ $reseller->name }}'s DirectAdmin accounts to Application Hosting one node at a time.
                    Every user still on DirectAdmin is listed here, including accounts that were never imported.
                    Customers who are not on Talksasa yet get a platform login (name from the domain) and an info@ inbox when you queue convert.
                    Accounts already on a container with Cloudflare nameservers active are hidden.
                </p>
            </div>
            <a href="{{ route('admin.resellers.show', ['user' => $reseller, 'tab' => 'services']) }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">
                Back to reseller
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 dark:bg-emerald-950/40 p-4 text-sm text-emerald-900 dark:text-emerald-100">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif

    <form id="da-import-packages" method="POST" action="{{ route('admin.resellers.directadmin-offramp.import-packages', $reseller) }}">
        @csrf
    </form>
    <form method="POST" action="{{ route('admin.resellers.directadmin-offramp.store', $reseller) }}" class="space-y-6">
        @csrf
        <div class="ui-card p-6">
            <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                <h2 class="font-semibold text-lg">DirectAdmin accounts</h2>
                <div class="flex items-center gap-3">
                    <p class="text-sm text-slate-500">{{ $accounts->count() }} eligible</p>
                    <button form="da-import-packages" class="px-3 py-1.5 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">
                        Import DA packages
                    </button>
                </div>
            </div>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                Customers stay on this reseller’s catalog names and prices. Import pulls their DirectAdmin packages into Application Hosting listings first.
            </p>
            @if ($accounts->isEmpty())
                <p class="text-sm text-slate-500">No DirectAdmin accounts are waiting to convert. Linked container sites that already use Cloudflare nameservers are hidden.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-200 dark:border-slate-700">
                                <th class="py-2 pr-3"><input type="checkbox" @click="Object.keys(selected).forEach(k => selected[k] = $event.target.checked)"></th>
                                <th class="py-2 pr-4">Service</th>
                                <th class="py-2 pr-4">Customer</th>
                                <th class="py-2 pr-4">Domain / node</th>
                                <th class="py-2 pr-4">DA package → listing</th>
                                <th class="py-2 pr-4">Captured</th>
                                <th class="py-2">Convert status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accounts as $account)
                                @php
                                    $service = $account['service'];
                                    $map = $packageMap[$account['key']] ?? [];
                                @endphp
                                <tr class="border-b border-slate-100 dark:border-slate-800">
                                    <td class="py-3 pr-3">
                                        <input type="checkbox" name="account_keys[]" value="{{ $account['key'] }}" x-model="selected['{{ $account['key'] }}']" class="rounded border-slate-300">
                                    </td>
                                    <td class="py-3 pr-4">
                                        @if ($service)
                                            <a href="{{ route('admin.services.migrate-to-container', $service) }}" class="text-blue-600 hover:underline font-medium">#{{ $service->id }} {{ $service->name }}</a>
                                            <p class="text-xs text-slate-500">{{ $service->product?->name }}</p>
                                        @else
                                            <span class="font-medium">{{ $account['da_username'] }}</span>
                                            <p class="text-xs text-amber-700">Not on Talksasa yet — convert will create the customer</p>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4">
                                        @if ($account['customer'])
                                            {{ $account['customer']->name }}
                                            <div class="text-xs text-slate-500">{{ $account['customer']->email }}</div>
                                        @else
                                            <span>{{ $account['proposed_name'] }}</span>
                                            <div class="text-xs text-slate-500">Will create {{ $account['proposed_email'] ?: 'info@…' }}</div>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4 font-mono text-xs">
                                        {{ $account['domain'] ?: '—' }}
                                        <div class="text-slate-500">{{ $account['node']?->name ?? 'no node' }}</div>
                                    </td>
                                    <td class="py-3 pr-4 text-xs">
                                        <div>{{ $map['da_package'] ?? $account['package'] ?: '—' }}</div>
                                        <div class="text-slate-500">
                                            {{ $map['listing']?->name ?? 'Import packages first' }}
                                            @if (! empty($map['retail']))
                                                · {{ number_format($map['retail'], 2) }}
                                            @endif
                                        </div>
                                        @if ($map['engine'] ?? null)
                                            <div class="text-slate-400">Size: {{ $map['engine']->name }}</div>
                                        @endif
                                        @if ($map['needs_price'] ?? false)
                                            <div class="text-amber-700">Listing has no retail price yet</div>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4 text-xs">
                                        @if ($account['snapshot']?->isCaptured())
                                            {{ $account['snapshot']->dns_record_count }} DNS
                                            · {{ $account['snapshot']->mailbox_count }} mail
                                            · {{ $account['snapshot']->database_count }} DB
                                        @else
                                            <span class="text-amber-700">Not captured</span>
                                        @endif
                                    </td>
                                    <td class="py-3">{{ $account['convert_status'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="ui-card p-6 space-y-4">
            <h2 class="font-semibold text-lg">Shared convert settings</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Fallback Application Hosting size</label>
                    <select name="product_id" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                        <option value="">Select a size</option>
                        @foreach ($containerProducts as $product)
                            <option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Used only when a listing has no container template yet. Retail price still comes from the reseller catalog.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Email Hosting product (if mailboxes exist)</label>
                    <select name="email_product_id" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                        <option value="">Use bundle / first Mailcow plan</option>
                        @foreach ($emailProducts as $product)
                            <option value="{{ $product->id }}" @selected(old('email_product_id') == $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="acknowledge_mail_pull" value="1" @checked(old('acknowledge_mail_pull')) class="mt-1">
                <span>Acknowledge mail pull to Mailcow for accounts that have mailboxes. Update MX only after IMAP sync has caught up.</span>
            </label>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="acknowledge_addon_sites" value="1" @checked(old('acknowledge_addon_sites')) class="mt-1">
                <span>Acknowledge extra live sites launch as sibling containers on the same Application Hosting package.</span>
            </label>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="confirm_silent" value="1" required class="mt-1">
                <span>Confirm this silent batch. Customers are not emailed. Converts run one-at-a-time per DirectAdmin node.</span>
            </label>
            <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Queue selected converts</button>
        </div>
    </form>

    @foreach ($batches as $batch)
        <div class="ui-card p-6 space-y-4">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h2 class="font-semibold text-lg">Batch #{{ $batch->id }}</h2>
                    <p class="text-sm text-slate-500">
                        {{ $batch->status?->label() }} · {{ $batch->product?->name }}
                        · {{ $batch->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                    </p>
                    @if ($batch->error)
                        <p class="text-sm text-red-700 mt-1">{{ $batch->error }}</p>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('admin.resellers.directadmin-offramp.cut-dns', [$reseller, $batch]) }}">
                @csrf
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-200 dark:border-slate-700">
                                <th class="py-2 pr-3"></th>
                                <th class="py-2 pr-4">Account</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Stack / mail</th>
                                <th class="py-2 pr-4">DNS / SSL</th>
                                <th class="py-2">Cutover</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($batch->items as $item)
                                @php $notes = is_array($item->cutover_notes) ? $item->cutover_notes : []; @endphp
                                <tr class="border-b border-slate-100 dark:border-slate-800 align-top">
                                    <td class="py-3 pr-3">
                                        <input type="checkbox" name="item_ids[]" value="{{ $item->id }}" class="rounded border-slate-300">
                                    </td>
                                    <td class="py-3 pr-4">
                                        <a href="{{ route('admin.services.show', $item->service_id) }}" class="text-blue-600 hover:underline">#{{ $item->service_id }}</a>
                                        <div class="text-xs text-slate-500">{{ $item->service?->user?->name }}</div>
                                        <div class="font-mono text-xs">{{ $item->hostname ?: '—' }}</div>
                                        <a href="{{ route('admin.services.migrate-to-container', $item->service_id) }}" class="text-xs text-blue-600 hover:underline">Open existing wizard</a>
                                        <button type="button" class="block text-xs text-teal-700 hover:underline" @click.prevent="window.dispatchEvent(new CustomEvent('da-convert-watch', { detail: { itemId: {{ $item->id }} } }))">Watch convert</button>
                                    </td>
                                    <td class="py-3 pr-4">
                                        {{ $item->status?->label() }}
                                        @if ($item->error)
                                            <p class="text-xs text-red-700 mt-1">{{ $item->error }}</p>
                                        @endif
                                        @if (! empty($item->blockers))
                                            <p class="text-xs text-amber-700 mt-1">{{ implode(' ', $item->blockers) }}</p>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4 text-xs">
                                        {{ $item->detected_stack ?: '—' }}
                                        <div>{{ $item->mailbox_count }} mailbox(es)</div>
                                        @if ($item->has_addon_sites)
                                            <div>Has addon sites</div>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4 text-xs">
                                        <div>DNS {{ $item->dns_ok ? 'ok' : 'pending' }}{{ $item->dns_managed ? ' (Cloudflare)' : ' (external)' }}</div>
                                        <div>SSL {{ $item->ssl_ok ? 'ok' : 'pending' }}</div>
                                    </td>
                                    <td class="py-3 text-xs font-mono break-all">
                                        @if (! empty($notes['target_ip']))
                                            A {{ $notes['hostname'] ?? $item->hostname }} → {{ $notes['target_ip'] }}
                                            @if (! empty($notes['www']) && ($notes['www'] ?? '') !== ($notes['hostname'] ?? ''))
                                                <div>A {{ $notes['www'] }} → {{ $notes['target_ip'] }}</div>
                                            @endif
                                        @endif
                                        @if (! empty($notes['instruction']))
                                            <div class="font-sans text-slate-500 mt-1">{{ $notes['instruction'] }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button class="mt-4 px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-sm">Cut web DNS for selected</button>
            </form>
        </div>
    @endforeach

    @include('admin.resellers.partials.da-convert-progress-terminal', [
        'reseller' => $reseller,
        'convertProgress' => $convertProgress ?? ['is_active' => false, 'active_count' => 0, 'items' => [], 'current' => null],
    ])
</div>
@endsection
