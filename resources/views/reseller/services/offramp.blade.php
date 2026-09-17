@extends('layouts.reseller')

@section('title', 'Move to Application Hosting')

@php
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $planLabel = function ($listing) use ($fmt) {
        $limits = $listing->hasContainerResourceLimits() ? $listing->containerResourceLimits() : [];
        $engine = $listing->adminProduct;
        if ($limits === [] && $engine) {
            $engine->loadMissing('containerTemplate');
            $included = $engine->getIncludedContainerLimits($engine->containerTemplate);
            $limits = ['cpu' => $included['cpu'] ?? null, 'memory_mb' => $included['memory_mb'] ?? null, 'disk_gb' => $included['disk_gb'] ?? null];
        }
        $bits = array_filter([
            ! empty($limits['cpu']) ? $fmt($limits['cpu']).' vCPU' : null,
            ! empty($limits['memory_mb']) ? number_format(((int) $limits['memory_mb']) / 1024, 1).' GB RAM' : null,
            ! empty($limits['disk_gb']) ? $fmt($limits['disk_gb']).' GB disk' : null,
        ]);

        return $listing->name.($bits !== [] ? ' · '.implode(' / ', $bits) : '');
    };
    $defaultFallbackId = old('reseller_product_id', '');
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
        'security' => $account['security'] ?? ['state' => 'pending', 'label' => ''],
        'can_relink' => (bool) ($account['can_relink'] ?? false),
        'can_restart' => (bool) ($account['can_restart'] ?? false),
        'can_repull' => (bool) ($account['can_repull'] ?? false),
        'sibling_count' => (int) ($account['sibling_count'] ?? 0),
    ])->values();
    $daUsernames = $daUsernames ?? [];
    $daNodes = $daNodes ?? collect();
    $daListing = $daListing ?? ['state' => 'unbound', 'message' => '', 'listed' => 0, 'unlinked' => 0];
    $cpu = $computePool['cpu'] ?? [];
    $memory = $computePool['memory'] ?? [];
@endphp

@section('content')
<div
    class="space-y-6"
    x-data="daOfframpBoard(@js($boardRows), @js(route('reseller.directadmin-offramp.progress')), @js(route('reseller.directadmin-offramp.restart', ['batch' => '__BATCH__', 'item' => '__ITEM__'])))"
>
    <div class="rounded-xl border p-4 text-sm" :class="notice.ok ? 'border-emerald-200 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-900 dark:text-emerald-100' : 'border-red-200 bg-red-50 text-red-800'" x-show="notice.text" x-text="notice.text" x-cloak></div>
    <div class="ui-card p-6">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Move to Application Hosting</h1>
                <p class="text-slate-600 dark:text-slate-400 mt-1">
                    Your DirectAdmin accounts, moved onto your own Application Hosting plans. Each site is exported, deployed, scanned for malware, hardened and brought live. Billing dates and prices stay as they are and the platform never emails your customers.
                </p>
                @if (($cpu['pool'] ?? 0) > 0 || ($memory['pool'] ?? 0) > 0)
                    <p class="text-xs text-slate-500 mt-2">
                        Pool left for moves: <span class="font-medium">{{ $fmt($cpu['remaining'] ?? 0) }} of {{ $fmt($cpu['pool'] ?? 0) }} vCPU</span>,
                        <span class="font-medium">{{ number_format(((int) ($memory['remaining'] ?? 0)) / 1024, 1) }} of {{ number_format(((int) ($memory['pool'] ?? 0)) / 1024, 1) }} GB RAM</span>. An account that does not fit is skipped with the shortfall.
                    </p>
                @endif
            </div>
            <a href="{{ route('reseller.services.index') }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">Back to services</a>
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

    @if ($plans->isEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950/30 p-4 text-sm text-amber-900 dark:text-amber-100">
            You have no active Application Hosting plan yet. <a href="{{ route('reseller.catalog.create') }}" class="underline font-medium">Create one</a> with the specs you want to sell, or import your DirectAdmin packages below to map each package to a plan.
        </div>
    @endif

    <form id="da-import-packages" method="POST" action="{{ route('reseller.directadmin-offramp.import-packages') }}">
        @csrf
    </form>

    <form id="da-offramp" method="POST" action="{{ route('reseller.directadmin-offramp.store') }}" class="space-y-6">
        @csrf
        <div class="ui-card p-6 space-y-4">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <h2 class="font-semibold text-lg">Accounts</h2>
                <div class="flex items-center gap-3 text-sm text-slate-500">
                    <span x-text="`${rows.length} account${rows.length === 1 ? '' : 's'}`"></span>
                    @if (filled($reseller->directadmin_username))
                        <a href="{{ route('reseller.directadmin-offramp', ['refresh' => 1]) }}" class="px-3 py-1.5 border border-slate-300 dark:border-slate-600 rounded-lg text-sm text-slate-700 dark:text-slate-200">Refresh from DirectAdmin</a>
                    @endif
                    <button form="da-import-packages" class="px-3 py-1.5 border border-slate-300 dark:border-slate-600 rounded-lg text-sm text-slate-700 dark:text-slate-200">Import my DirectAdmin packages</button>
                </div>
            </div>

            @if (! empty($daListing['message']))
                <p class="text-xs rounded-lg px-3 py-2 {{ ($daListing['state'] ?? '') === 'ok' ? 'bg-slate-50 dark:bg-slate-900/60 text-slate-600 dark:text-slate-300' : 'bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-200 border border-amber-200 dark:border-amber-800' }}">
                    {{ $daListing['message'] }}
                </p>
            @endif

            @if ($accounts->isEmpty())
                <p class="text-sm text-slate-500">No DirectAdmin accounts are waiting. Sites already running on Application Hosting stay out of this list.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-200 dark:border-slate-700">
                                <th class="py-2 pr-3">
                                    <input type="checkbox" class="rounded border-slate-300" :checked="allQueueableSelected()" x-on:change="toggleAll($event.target.checked)" title="Select all ready accounts">
                                </th>
                                <th class="py-2 pr-4">Customer</th>
                                <th class="py-2 pr-4">Plan</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Security</th>
                                <th class="py-2">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accounts as $account)
                                @php
                                    $service = $account['service'];
                                    $map = $packageMap[$account['key']] ?? [];
                                    $mappedListing = $map['listing'] ?? null;
                                    $mappedIsPlan = $mappedListing && $mappedListing->type === 'container_hosting' && $plans->contains('id', $mappedListing->id);
                                    $selectedPlan = old('plans.'.$account['key'], $mappedIsPlan ? $mappedListing->id : '');
                                @endphp
                                <tr class="border-b border-slate-100 dark:border-slate-800 align-top" data-account-key="{{ $account['key'] }}">
                                    <td class="py-3 pr-3">
                                        <input type="checkbox" name="account_keys[]" value="{{ $account['key'] }}" class="rounded border-slate-300"
                                               :checked="selected.includes(@js($account['key']))"
                                               x-on:change="toggleKey(@js($account['key']), $event.target.checked)"
                                               :disabled="!row(@js($account['key']))?.can_queue">
                                    </td>
                                    <td class="py-3 pr-4">
                                        <div class="font-medium text-slate-900 dark:text-white">{{ $account['customer']?->name ?? $account['proposed_name'] }}</div>
                                        <div class="font-mono text-xs text-slate-600 dark:text-slate-300">{{ $account['domain'] ?: ($account['da_username'] ?: '—') }}</div>
                                        @if ($service)
                                            <a href="{{ route('reseller.services.show', $service) }}" class="text-xs text-violet-700 dark:text-violet-300 hover:underline">Service #{{ $service->id }}</a>
                                            @if (($account['da_listed'] ?? null) === true)
                                                <p class="text-xs text-emerald-700 dark:text-emerald-300">Live on DirectAdmin as <span class="font-mono">{{ $account['da_username'] }}</span></p>
                                            @elseif (($account['da_listed'] ?? null) === false)
                                                <p class="text-xs text-amber-700 dark:text-amber-300">Not on DirectAdmin under your login as <span class="font-mono">{{ $account['da_username'] ?: '(no username)' }}</span>: nothing to pull for this row.</p>
                                            @endif
                                            @if (! empty($account['duplicate_service_ids']))
                                                <p class="text-xs text-amber-700 dark:text-amber-300">Same domain as service #{{ implode(', #', $account['duplicate_service_ids']) }}. Move the row that is live on DirectAdmin and delete the other from its service page.</p>
                                            @endif
                                        @else
                                            <p class="text-xs text-amber-700">Not in your customer list yet. Moving it creates the customer as {{ $account['proposed_email'] ?: 'info@…' }}</p>
                                        @endif
                                        @if (! empty($account['package']))
                                            <p class="text-xs text-slate-500">DirectAdmin package: {{ $account['package'] }}</p>
                                        @endif
                                        @if (! empty($account['suspended_on_da']))
                                            <p class="text-xs text-red-700 dark:text-red-300">Suspended on DirectAdmin. Unsuspend it before moving it.</p>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4">
                                        @if ($plans->isNotEmpty())
                                            <select name="plans[{{ $account['key'] }}]" form="da-offramp" class="w-full max-w-xs px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-xs" :disabled="!row(@js($account['key']))?.can_queue">
                                                <option value="">{{ $mappedListing && ! $mappedIsPlan ? 'Mapped: '.$mappedListing->name : 'Use the fallback plan' }}</option>
                                                @foreach ($plans as $plan)
                                                    <option value="{{ $plan->id }}" @selected((string) $selectedPlan === (string) $plan->id)>{{ $planLabel($plan) }}</option>
                                                @endforeach
                                            </select>
                                        @elseif ($mappedListing)
                                            <span class="text-xs text-slate-600 dark:text-slate-300">{{ $mappedListing->name }}</span>
                                        @else
                                            <span class="text-xs text-amber-700">No plan mapped</span>
                                        @endif
                                        @if (($map['needs_price'] ?? false) && ($account['can_queue'] ?? false))
                                            <p class="text-xs text-amber-700 mt-1">This plan has no price yet</p>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                              :class="statusClass(row(@js($account['key'])))"
                                              x-text="row(@js($account['key']))?.status_label || @js($account['status_label'] ?? 'Ready')"></span>
                                        <p class="text-xs text-slate-500 mt-1" x-show="row(@js($account['key']))?.step" x-text="row(@js($account['key']))?.step"></p>
                                        <p class="text-xs text-red-700 mt-1" x-show="row(@js($account['key']))?.error" x-text="row(@js($account['key']))?.error"></p>
                                        <p class="text-xs text-slate-500 mt-1" x-text="row(@js($account['key']))?.container_label || @js($account['container_label'] ?? 'No container')"></p>
                                    </td>
                                    <td class="py-3 pr-4 text-xs">
                                        <span :class="securityClass(row(@js($account['key'])))" x-text="row(@js($account['key']))?.security?.label || ''"></span>
                                    </td>
                                    <td class="py-3">
                                        <div class="flex flex-wrap gap-2">
                                            <template x-if="row(@js($account['key']))?.can_retry">
                                                <button type="submit" form="da-retry" name="account_key" value="{{ $account['key'] }}" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-xs font-medium">Retry</button>
                                            </template>
                                            <template x-if="row(@js($account['key']))?.can_restart && row(@js($account['key']))?.cutover_item_id">
                                                <button type="button" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-xs font-medium disabled:opacity-50"
                                                        :disabled="restartingKey === @js($account['key'])"
                                                        x-on:click.prevent="restartRow(@js($account['key']))"
                                                        x-text="restartingKey === @js($account['key']) ? 'Restarting…' : 'Restart'"></button>
                                            </template>
                                            <template x-if="row(@js($account['key']))?.can_cut_dns && row(@js($account['key']))?.cutover_batch_id">
                                                <button type="submit" :form="'da-cut-' + row(@js($account['key']))?.cutover_batch_id + '-' + row(@js($account['key']))?.cutover_item_id" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-xs font-medium">Cut web DNS</button>
                                            </template>
                                            <button type="button" class="px-3 py-1.5 border border-slate-300 dark:border-slate-600 rounded-lg text-xs"
                                                    x-on:click.prevent="window.dispatchEvent(new CustomEvent('da-convert-watch', { detail: { itemId: row(@js($account['key']))?.cutover_item_id } }))"
                                                    x-show="row(@js($account['key']))?.cutover_item_id">Watch</button>
                                            @if ($service)
                                                <button type="button" class="px-3 py-1.5 border border-slate-300 dark:border-slate-600 rounded-lg text-xs"
                                                        x-show="row(@js($account['key']))?.can_relink"
                                                        x-on:click.prevent="toggleFix(@js($account['key']))"
                                                        x-text="fixingKey === @js($account['key']) ? 'Cancel' : 'Fix DirectAdmin login'"></button>
                                                <button type="button" class="px-3 py-1.5 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg text-xs"
                                                        x-show="row(@js($account['key']))?.can_repull"
                                                        x-on:click.prevent="toggleRepull(@js($account['key']))"
                                                        x-text="repullKey === @js($account['key']) ? 'Cancel' : 'Pull again from DirectAdmin'"></button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @if ($service)
                                    <tr class="border-b border-slate-100 dark:border-slate-800 bg-red-50 dark:bg-red-950/30" x-show="repullKey === @js($account['key'])" x-cloak>
                                        <td colspan="6" class="py-3 px-3">
                                            <form method="POST" action="{{ route('reseller.directadmin-offramp.repull', $service) }}" class="space-y-2">
                                                @csrf
                                                <p class="text-sm font-medium text-red-800 dark:text-red-200">Pull {{ $account['domain'] ?: $service->name }} from DirectAdmin again, from scratch.</p>
                                                <p class="text-xs text-slate-600 dark:text-slate-300">
                                                    This removes the running container with its files and database<span x-show="row(@js($account['key']))?.sibling_count > 0">, and the <span x-text="row(@js($account['key']))?.sibling_count"></span> sibling site container(s) made for its extra domains</span>.
                                                    Anything uploaded or changed on the container since the first pull is lost. The email service, billing, domains and backups are kept.
                                                    The account is then exported from DirectAdmin as it is today, deployed, imported and brought live again, and you cut web DNS once more when it is ready.
                                                </p>
                                                <label class="flex items-center gap-2 text-xs">
                                                    <input type="checkbox" name="confirm_wipe" value="1" required>
                                                    <span>I understand the container-side copy is discarded and replaced by what DirectAdmin holds now.</span>
                                                </label>
                                                <button class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-xs font-medium">Wipe and pull again</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <tr class="border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/60" x-show="fixingKey === @js($account['key'])" x-cloak>
                                        <td colspan="6" class="py-3 px-3">
                                            <form method="POST" action="{{ route('reseller.directadmin-offramp.relink', $service) }}" class="flex flex-wrap items-end gap-3">
                                                @csrf
                                                <div>
                                                    <label class="block text-xs font-medium mb-1" for="da-username-{{ $service->id }}">DirectAdmin username</label>
                                                    <input id="da-username-{{ $service->id }}" name="directadmin_username" list="da-usernames" required maxlength="64" pattern="[A-Za-z0-9._-]+"
                                                           value="{{ old('directadmin_username', $account['da_username'] ?? '') }}"
                                                           class="w-56 px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-xs font-mono">
                                                </div>
                                                @if ($daNodes->count() > 1)
                                                    <div>
                                                        <label class="block text-xs font-medium mb-1" for="da-node-{{ $service->id }}">DirectAdmin server</label>
                                                        <select id="da-node-{{ $service->id }}" name="node_id" class="px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-xs">
                                                            @foreach ($daNodes as $daNode)
                                                                <option value="{{ $daNode->id }}" @selected((int) ($account['node_id'] ?? 0) === (int) $daNode->id)>{{ $daNode->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @elseif ($daNodes->count() === 1)
                                                    <input type="hidden" name="node_id" value="{{ $daNodes->first()->id }}">
                                                @endif
                                                <label class="flex items-center gap-2 text-xs pb-2">
                                                    <input type="checkbox" name="retry" value="1" checked>
                                                    <span>Retry the move right after saving</span>
                                                </label>
                                                <button class="px-3 py-1.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-xs font-medium">Save and retry</button>
                                                <p class="basis-full text-xs text-slate-500">
                                                    The user must exist on that server and belong to your DirectAdmin reseller login.
                                                    @if ($daUsernames !== [])
                                                        Pick from the list of your {{ count($daUsernames) }} account(s) not yet linked, or type the username DirectAdmin shows.
                                                    @elseif (($daListing['state'] ?? '') === 'ok')
                                                        Every account DirectAdmin lists under your login is already linked to a row here, so if this row's username is wrong the right one is on another row: retry that row instead, or remove this duplicate.
                                                    @else
                                                        No account list is available: {{ $daListing['message'] ?? 'your DirectAdmin login is not connected.' }}
                                                    @endif
                                                </p>
                                            </form>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="ui-card p-6 space-y-4">
            <h2 class="font-semibold text-lg">Move settings</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Fallback plan</label>
                    <select name="reseller_product_id" form="da-offramp" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" x-ref="product">
                        <option value="">None: every account must have a plan above</option>
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}" @selected((string) $defaultFallbackId === (string) $plan->id)>{{ $planLabel($plan) }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Used for accounts whose plan is left on the fallback.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Email plan for accounts with mailboxes</label>
                    <select name="email_reseller_product_id" form="da-offramp" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" x-ref="email">
                        <option value="">Platform default email plan</option>
                        @foreach ($emailPlans as $plan)
                            <option value="{{ $plan->id }}" @selected(old('email_reseller_product_id') == $plan->id)>{{ $plan->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Mailboxes are copied to the mail platform. No invoice is raised for the move.</p>
                </div>
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="acknowledge_mail_pull" value="1" form="da-offramp" @checked(old('acknowledge_mail_pull', true)) class="mt-1" x-ref="mailAck">
                <span>Pull mailboxes when the account has mail. Point MX at the mail platform once the copy has caught up.</span>
            </label>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="acknowledge_addon_sites" value="1" form="da-offramp" @checked(old('acknowledge_addon_sites', true)) class="mt-1" x-ref="addonAck">
                <span>Launch extra live sites on the account as sibling containers on the same plan.</span>
            </label>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="confirm_silent" value="1" required class="mt-1">
                <span>I understand the platform does not email my customers about this move. I will tell them myself.</span>
            </label>
            <button class="px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-sm font-medium">Move selected accounts</button>
        </div>
    </form>

    <datalist id="da-usernames">
        @foreach ($daUsernames as $daUsername)
            <option value="{{ $daUsername }}"></option>
        @endforeach
    </datalist>

    <form id="da-retry" method="POST" action="{{ route('reseller.directadmin-offramp.retry') }}">
        @csrf
        <input type="hidden" name="reseller_product_id" :value="$refs.product?.value">
        <input type="hidden" name="email_reseller_product_id" :value="$refs.email?.value">
        <input type="hidden" name="acknowledge_mail_pull" value="1" x-bind:disabled="!$refs.mailAck?.checked">
        <input type="hidden" name="acknowledge_addon_sites" value="1" x-bind:disabled="!$refs.addonAck?.checked">
    </form>

    @foreach ($accounts as $account)
        @if (! empty($account['can_cut_dns']) && ! empty($account['cutover_batch_id']) && ! empty($account['cutover_item_id']))
            <form id="da-cut-{{ $account['cutover_batch_id'] }}-{{ $account['cutover_item_id'] }}" method="POST" action="{{ route('reseller.directadmin-offramp.cut-dns', $account['cutover_batch_id']) }}">
                @csrf
                <input type="hidden" name="item_ids[]" value="{{ $account['cutover_item_id'] }}">
            </form>
        @endif
    @endforeach

    @include('admin.resellers.partials.da-convert-progress-terminal', [
        'reseller' => $reseller,
        'progressUrl' => route('reseller.directadmin-offramp.progress'),
        'convertProgress' => $convertProgress ?? ['is_active' => false, 'active_count' => 0, 'items' => [], 'current' => null, 'accounts' => []],
    ])
</div>
@endsection

@push('scripts')
<script>
function daOfframpBoard(initialRows, url, restartUrlTemplate) {
    return {
        rows: initialRows || [],
        selected: [],
        fixingKey: null,
        repullKey: null,
        restartingKey: null,
        notice: { ok: true, text: '' },
        url,
        restartUrlTemplate,
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

        toggleFix(key) {
            this.fixingKey = this.fixingKey === key ? null : key;
            if (this.fixingKey) this.repullKey = null;
        },

        toggleRepull(key) {
            this.repullKey = this.repullKey === key ? null : key;
            if (this.repullKey) this.fixingKey = null;
        },

        async restartRow(key) {
            const row = this.row(key);
            if (!row?.cutover_batch_id || !row?.cutover_item_id || this.restartingKey) return;
            this.restartingKey = key;
            this.notice = { ok: true, text: '' };
            const target = this.restartUrlTemplate
                .replace('__BATCH__', encodeURIComponent(row.cutover_batch_id))
                .replace('__ITEM__', encodeURIComponent(row.cutover_item_id));
            const token = document.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value || '';
            try {
                const response = await fetch(target, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
                    body: '{}',
                });
                let result = null;
                try { result = await response.json(); } catch (error) { result = null; }
                this.notice = {
                    ok: response.ok && Boolean(result?.ok),
                    text: result?.message || (response.ok ? 'Restarted.' : `Restart failed (HTTP ${response.status}).`),
                };
                await this.refresh();
                window.dispatchEvent(new CustomEvent('da-convert-watch', { detail: { itemId: result?.item_id || row.cutover_item_id } }));
            } catch (error) {
                console.error('Restart failed', error);
                this.notice = { ok: false, text: 'Restart failed: could not reach the server.' };
            } finally {
                this.restartingKey = null;
            }
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

        securityClass(row) {
            const state = row?.security?.state;
            if (state === 'review') return 'text-red-700 font-medium';
            if (state === 'archived') return 'text-amber-700 font-medium';
            if (state === 'clean') return 'text-emerald-700 font-medium';
            return 'text-slate-400';
        },

        schedulePoll() {
            if (this.pollTimer) clearInterval(this.pollTimer);
            this.pollTimer = setInterval(() => this.refresh(), 3000);
        },

        async refresh() {
            try {
                const response = await fetch(this.url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) return;
                const payload = await response.json();
                const updates = payload.accounts || [];
                if (!updates.length) return;
                this.rows = this.rows.map((row) => {
                    const next = updates.find((item) => item.key === row.key);
                    return next ? { ...row, ...next } : row;
                });
            } catch (error) {
                console.error('Move board refresh failed', error);
            }
        },
    };
}
</script>
@endpush
