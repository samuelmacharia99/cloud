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

@php
    $defaultProductId = old('product_id', $containerProducts->first()?->id);
    $boardRows = $accounts->map(fn ($account) => [
        'key' => $account['key'],
        'status' => $account['status'] ?? 'ready',
        'status_label' => $account['status_label'] ?? ($account['convert_status'] ?? 'Ready'),
        'step' => $account['step'] ?? null,
        'error' => $account['error'] ?? null,
        'container' => $account['container'] ?? 'none',
        'container_label' => $account['container_label'] ?? 'No container',
        'can_queue' => (bool) ($account['can_queue'] ?? true),
        'can_retry' => (bool) ($account['can_retry'] ?? false),
        'can_cut_dns' => (bool) ($account['can_cut_dns'] ?? false),
        'percent' => (int) ($account['percent'] ?? 0),
        'cutover_batch_id' => $account['cutover_batch_id'] ?? null,
        'cutover_item_id' => $account['cutover_item_id'] ?? null,
    ])->values();
@endphp

@section('content')
<div
    class="space-y-6"
    x-data="daOfframpBoard(@js($boardRows), @js(route('admin.resellers.directadmin-offramp.progress', $reseller)))"
>
    <div class="ui-card p-6">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">DirectAdmin off-ramp</h1>
                <p class="text-slate-600 dark:text-slate-400 mt-1">
                    Convert {{ $reseller->name }}'s remaining DirectAdmin users. Each row shows whether a container exists, the current step, and any error.
                </p>
                @if (filled($reseller->directadmin_username))
                    <p class="text-xs text-slate-500 mt-2">
                        DirectAdmin user list is cached for a few minutes so this page does not wait on the panel for every reload.
                    </p>
                @endif
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

    <form id="da-offramp" method="POST" action="{{ route('admin.resellers.directadmin-offramp.store', $reseller) }}" class="space-y-6">
        @csrf
        <div class="ui-card p-6 space-y-4">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <h2 class="font-semibold text-lg">Accounts</h2>
                <div class="flex items-center gap-3 text-sm text-slate-500">
                    <span x-text="`${rows.length} account${rows.length === 1 ? '' : 's'}`"></span>
                    @if (filled($reseller->directadmin_username))
                        <a href="{{ route('admin.resellers.directadmin-offramp', ['user' => $reseller, 'refresh' => 1]) }}" class="px-3 py-1.5 border border-slate-300 dark:border-slate-600 rounded-lg text-sm text-slate-700 dark:text-slate-200">
                            Refresh from DirectAdmin
                        </a>
                    @endif
                    <button form="da-import-packages" class="px-3 py-1.5 border border-slate-300 dark:border-slate-600 rounded-lg text-sm text-slate-700 dark:text-slate-200">
                        Import DA packages
                    </button>
                </div>
            </div>

            @if ($accounts->isEmpty())
                <p class="text-sm text-slate-500">No DirectAdmin accounts are waiting. Finished Cloudflare container sites stay hidden.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-200 dark:border-slate-700">
                                <th class="py-2 pr-3">
                                    <input
                                        type="checkbox"
                                        class="rounded border-slate-300"
                                        :checked="allQueueableSelected()"
                                        x-on:change="toggleAll($event.target.checked)"
                                        title="Select all ready accounts"
                                    >
                                </th>
                                <th class="py-2 pr-4">Customer</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Container</th>
                                <th class="py-2">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accounts as $account)
                                @php
                                    $service = $account['service'];
                                    $map = $packageMap[$account['key']] ?? [];
                                @endphp
                                <tr class="border-b border-slate-100 dark:border-slate-800 align-top" data-account-key="{{ $account['key'] }}">
                                    <td class="py-3 pr-3">
                                        <input
                                            type="checkbox"
                                            name="account_keys[]"
                                            value="{{ $account['key'] }}"
                                            class="rounded border-slate-300"
                                            :checked="selected.includes(@js($account['key']))"
                                            x-on:change="toggleKey(@js($account['key']), $event.target.checked)"
                                            :disabled="!row(@js($account['key']))?.can_queue"
                                        >
                                    </td>
                                    <td class="py-3 pr-4">
                                        <div class="font-medium text-slate-900 dark:text-white">
                                            {{ $account['customer']?->name ?? $account['proposed_name'] }}
                                        </div>
                                        <div class="font-mono text-xs text-slate-600 dark:text-slate-300">{{ $account['domain'] ?: ($account['da_username'] ?: '—') }}</div>
                                        @if ($service)
                                            <a href="{{ route('admin.services.show', $service) }}" class="text-xs text-blue-600 hover:underline">#{{ $service->id }}</a>
                                        @else
                                            <p class="text-xs text-amber-700">Not on Talksasa yet — will create {{ $account['proposed_email'] ?: 'info@…' }}</p>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="statusClass(row(@js($account['key'])))"
                                            x-text="row(@js($account['key']))?.status_label || @js($account['status_label'] ?? 'Ready')"
                                        ></span>
                                        <p class="text-xs text-slate-500 mt-1" x-show="row(@js($account['key']))?.step" x-text="row(@js($account['key']))?.step"></p>
                                        <p class="text-xs text-red-700 mt-1" x-show="row(@js($account['key']))?.error" x-text="row(@js($account['key']))?.error"></p>
                                        @if (($map['needs_price'] ?? false) && ($account['can_queue'] ?? false))
                                            <p class="text-xs text-amber-700 mt-1">Listing has no retail price yet</p>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4 text-xs text-slate-600 dark:text-slate-300">
                                        <span x-text="row(@js($account['key']))?.container_label || @js($account['container_label'] ?? 'No container')"></span>
                                    </td>
                                    <td class="py-3">
                                        <div class="flex flex-wrap gap-2">
                                            <template x-if="row(@js($account['key']))?.can_retry">
                                                <button
                                                    type="submit"
                                                    form="da-retry"
                                                    name="account_key"
                                                    value="{{ $account['key'] }}"
                                                    class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-xs font-medium"
                                                >Retry</button>
                                            </template>
                                            <template x-if="row(@js($account['key']))?.can_cut_dns && row(@js($account['key']))?.cutover_batch_id">
                                                <button
                                                    type="submit"
                                                    :form="'da-cut-' + row(@js($account['key']))?.cutover_batch_id + '-' + row(@js($account['key']))?.cutover_item_id"
                                                    class="px-3 py-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-xs font-medium"
                                                >Cut web DNS</button>
                                            </template>
                                            <button
                                                type="button"
                                                class="px-3 py-1.5 border border-slate-300 dark:border-slate-600 rounded-lg text-xs"
                                                x-on:click.prevent="window.dispatchEvent(new CustomEvent('da-convert-watch', { detail: { itemId: row(@js($account['key']))?.cutover_item_id } }))"
                                                x-show="row(@js($account['key']))?.cutover_item_id"
                                            >Watch convert</button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="ui-card p-6 space-y-4">
            <h2 class="font-semibold text-lg">Queue settings</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Fallback Application Hosting size</label>
                    <select name="product_id" required form="da-offramp" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" x-ref="product">
                        @foreach ($containerProducts as $product)
                            <option value="{{ $product->id }}" @selected((int) $defaultProductId === (int) $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Email Hosting product</label>
                    <select name="email_product_id" form="da-offramp" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" x-ref="email">
                        <option value="">Use bundle / first Mailcow plan</option>
                        @foreach ($emailProducts as $product)
                            <option value="{{ $product->id }}" @selected(old('email_product_id') == $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="acknowledge_mail_pull" value="1" form="da-offramp" @checked(old('acknowledge_mail_pull', true)) class="mt-1" x-ref="mailAck">
                <span>Pull mailboxes to Mailcow when the account has mail. Update MX after IMAP sync.</span>
            </label>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="acknowledge_addon_sites" value="1" form="da-offramp" @checked(old('acknowledge_addon_sites', true)) class="mt-1" x-ref="addonAck">
                <span>Launch extra live sites as sibling containers on the same package.</span>
            </label>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="confirm_silent" value="1" required class="mt-1">
                <span>Confirm this silent convert. Customers are not emailed.</span>
            </label>
            <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Queue selected converts</button>
        </div>
    </form>

    <form id="da-retry" method="POST" action="{{ route('admin.resellers.directadmin-offramp.retry', $reseller) }}">
        @csrf
        <input type="hidden" name="product_id" :value="$refs.product?.value">
        <input type="hidden" name="email_product_id" :value="$refs.email?.value">
        <input type="hidden" name="acknowledge_mail_pull" value="1" x-bind:disabled="!$refs.mailAck?.checked">
        <input type="hidden" name="acknowledge_addon_sites" value="1" x-bind:disabled="!$refs.addonAck?.checked">
    </form>

    @foreach ($accounts as $account)
        @if (! empty($account['can_cut_dns']) && ! empty($account['cutover_batch_id']) && ! empty($account['cutover_item_id']))
            <form
                id="da-cut-{{ $account['cutover_batch_id'] }}-{{ $account['cutover_item_id'] }}"
                method="POST"
                action="{{ route('admin.resellers.directadmin-offramp.cut-dns', [$reseller, $account['cutover_batch_id']]) }}"
            >
                @csrf
                <input type="hidden" name="item_ids[]" value="{{ $account['cutover_item_id'] }}">
            </form>
        @endif
    @endforeach

    @include('admin.resellers.partials.da-convert-progress-terminal', [
        'reseller' => $reseller,
        'convertProgress' => $convertProgress ?? ['is_active' => false, 'active_count' => 0, 'items' => [], 'current' => null, 'accounts' => []],
    ])
</div>
@endsection

@push('scripts')
<script>
function daOfframpBoard(initialRows, url) {
    return {
        rows: initialRows || [],
        selected: [],
        url,
        pollTimer: null,

        init() {
            this.schedulePoll();
        },

        row(key) {
            return (this.rows || []).find((item) => item.key === key) || null;
        },

        queueableKeys() {
            return (this.rows || []).filter((item) => item.can_queue).map((item) => item.key);
        },

        allQueueableSelected() {
            const keys = this.queueableKeys();
            return keys.length > 0 && keys.every((key) => this.selected.includes(key));
        },

        toggleAll(checked) {
            this.selected = checked ? this.queueableKeys() : [];
        },

        toggleKey(key, checked) {
            if (checked && !this.selected.includes(key)) {
                this.selected = [...this.selected, key];
                return;
            }
            if (!checked) {
                this.selected = this.selected.filter((item) => item !== key);
            }
        },

        statusClass(row) {
            const status = row?.status;
            if (status === 'failed' || status === 'blocked') return 'bg-red-100 text-red-800';
            if (status === 'queued' || status === 'creating' || status === 'importing') return 'bg-sky-100 text-sky-800';
            if (status === 'waiting_dns' || status === 'waiting_mx' || status === 'done') return 'bg-emerald-100 text-emerald-800';
            return 'bg-slate-100 text-slate-700';
        },

        schedulePoll() {
            if (this.pollTimer) clearInterval(this.pollTimer);
            this.pollTimer = setInterval(() => this.refresh(), 3000);
        },

        async refresh() {
            try {
                const response = await fetch(this.url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) return;
                const payload = await response.json();
                const updates = payload.accounts || [];
                if (!updates.length) return;
                this.rows = this.rows.map((row) => {
                    const next = updates.find((item) => item.key === row.key);
                    return next ? { ...row, ...next } : row;
                });
            } catch (error) {
                console.error('Off-ramp status refresh failed', error);
            }
        },
    };
}
</script>
@endpush
