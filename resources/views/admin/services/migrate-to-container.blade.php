@extends('layouts.admin')

@section('title', 'Convert to Application Hosting')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.services.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Services</a>
    <span class="text-slate-400">/</span>
    <a href="{{ route('admin.services.show', $service) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $service->name }}</a>
    <span class="text-slate-400">/</span>
    <span class="font-medium text-slate-600 dark:text-slate-400">Convert to Application Hosting</span>
</div>
@endsection

@section('content')
@php
    $inventory = $preflight['inventory'] ?? null;
    $account = is_array($inventory['account'] ?? null) ? $inventory['account'] : [];
    $products = collect($containerProducts ?? $wordpressProducts ?? ($preflight['container_products'] ?? $preflight['wordpress_products'] ?? []));
    $recommendedProducts = collect($recommendedProducts ?? ($preflight['recommended_products'] ?? []));
    $recommendedIds = $recommendedProducts->pluck('id')->all();
    $otherProducts = $products->reject(fn ($product) => in_array($product->id, $recommendedIds, true));
    $activeRecommended = $recommendedProducts->where('is_active', true);
    $activeOther = $otherProducts->where('is_active', true);
    $inactiveProducts = $products->where('is_active', false);
    $canConvert = (bool) ($preflight['can_convert'] ?? false);
    $detectedStack = $preflight['detected_stack'] ?? ($inventory['stack'] ?? 'unknown');
    $sites = is_array($inventory['sites'] ?? null) ? $inventory['sites'] : [];
    $addonSiteCount = (int) ($inventory['addon_site_count'] ?? 0);
    $emailProducts = collect($preflight['email_products'] ?? []);
    $mustPullMail = (bool) ($preflight['must_pull_mail'] ?? false);
    $daEmailCount = (int) ($preflight['da_email_count'] ?? ($account['counts']['email'] ?? 0));
    $mailboxByDomain = is_array($preflight['email']['by_domain'] ?? null) ? $preflight['email']['by_domain'] : [];
    $emailInventoryWarnings = array_values(array_filter(array_merge(
        $preflight['email']['warnings'] ?? [],
        $preflight['database_warnings'] ?? [],
    )));
@endphp
<div class="space-y-6 max-w-4xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Convert to Application Hosting</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">
            Admin-only, convert-in-place. Same service ID — no second service, no invoice today, no customer notification.
            You choose the Application Hosting plan. DirectAdmin due date is kept; when that term ends, cron generates a new invoice for the selected plan.
            Extra live sites on this DA user become sibling containers on that same package — not extra billed plans. Combined usage above package specs is billed as overage.
            Mailboxes are pulled to Mailcow so this account can leave DirectAdmin. Update MX when IMAP sync has caught up.
            Prefer migrating mail to <strong>Mailcow</strong> first (or leave MX on DA only as a temporary bridge).
            Stacks are detected per site (WordPress, Laravel, Node.js, PHP, or static), including apps whose <code class="font-mono text-xs">package.json</code> or <code class="font-mono text-xs">artisan</code> sits above <code class="font-mono text-xs">public_html</code>.
        </p>
    </div>

    @if ($convertMeta)
        <div class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 p-4 text-sm">
            <p class="font-semibold">Last convert status: <span class="uppercase">{{ $convertMeta['status'] ?? 'n/a' }}</span></p>
            @if (!empty($convertMeta['error']))
                <p class="text-red-600 mt-1">{{ $convertMeta['error'] }}</p>
            @endif
            @if (!empty($convertMeta['steps']) && is_array($convertMeta['steps']))
                <ul class="mt-2 font-mono text-xs space-y-1 text-slate-600 dark:text-slate-300">
                    @foreach ($convertMeta['steps'] as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($preflightError)
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $preflightError }}</div>
    @endif

    {{-- Always show what this DA service is --}}
    <div class="ui-card p-6 space-y-4">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="font-semibold text-lg text-slate-900 dark:text-white">DirectAdmin service</h2>
            <a href="{{ route('admin.services.show', $service) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">Open full service page</a>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 p-3">
                <dt class="text-slate-500 text-xs uppercase tracking-wide">Customer</dt>
                <dd class="font-medium mt-1">{{ $service->user?->name ?? '—' }}</dd>
                <dd class="text-xs text-slate-500 font-mono">{{ $service->user?->email ?? ('#'.$service->user_id) }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 p-3">
                <dt class="text-slate-500 text-xs uppercase tracking-wide">Platform plan</dt>
                <dd class="font-medium mt-1">{{ $account['platform_product'] ?? $service->product?->name ?? '—' }}</dd>
                <dd class="text-xs text-slate-500">Status: {{ $account['status'] ?? ($service->status?->value ?? '—') }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 p-3">
                <dt class="text-slate-500 text-xs uppercase tracking-wide">Billing kept</dt>
                <dd class="font-medium mt-1">{{ $currentDue?->format('Y-m-d') ?? ($account['next_due_date'] ?? '—') }} · {{ $currentCycle }}</dd>
                <dd class="text-xs text-slate-500">
                    @if ($service->custom_price !== null)
                        Custom price {{ number_format((float) $service->custom_price, 2) }} (cleared on convert)
                    @else
                        Uses product price
                    @endif
                </dd>
            </div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 p-3">
                <dt class="text-slate-500 text-xs uppercase tracking-wide">DA username</dt>
                <dd class="font-mono mt-1">{{ $inventory['username'] ?? ($service->external_reference ?? ($service->service_meta['username'] ?? '—')) }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 p-3">
                <dt class="text-slate-500 text-xs uppercase tracking-wide">Primary domain</dt>
                <dd class="font-mono mt-1 break-all">{{ $inventory['domain'] ?? ($service->service_meta['domain'] ?? '—') }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 p-3">
                <dt class="text-slate-500 text-xs uppercase tracking-wide">DA package</dt>
                <dd class="font-medium mt-1">{{ $account['da_package'] ?? ($service->service_meta['package_name'] ?? $service->product?->directAdminPackage?->name ?? '—') }}</dd>
                @if (!empty($account['da_package_key']))
                    <dd class="text-xs font-mono text-slate-500">{{ $account['da_package_key'] }}</dd>
                @endif
            </div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 p-3">
                <dt class="text-slate-500 text-xs uppercase tracking-wide">Node</dt>
                <dd class="font-medium mt-1">{{ $account['node'] ?? $service->node?->name ?? '—' }}</dd>
                <dd class="text-xs font-mono text-slate-500">{{ $account['node_hostname'] ?? $service->node?->hostname ?? $service->node?->ip_address ?? '' }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 p-3">
                <dt class="text-slate-500 text-xs uppercase tracking-wide">Detected stack</dt>
                <dd class="font-medium mt-1">{{ \App\Services\Provisioning\DirectAdminToContainerConvertService::stackLabel($detectedStack) }}</dd>
                <dd class="text-xs text-slate-500">wp-config: {{ $inventory ? (($inventory['has_wp_config'] ?? false) ? 'yes' : 'no') : '—' }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 p-3">
                <dt class="text-slate-500 text-xs uppercase tracking-wide">Docroot</dt>
                <dd class="font-mono text-xs mt-1 break-all">{{ $inventory['docroot'] ?? '—' }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 p-3">
                <dt class="text-slate-500 text-xs uppercase tracking-wide">App root (export)</dt>
                <dd class="font-mono text-xs mt-1 break-all">{{ $inventory['app_root'] ?? $inventory['docroot'] ?? '—' }}</dd>
            </div>
        </dl>

        @if ($sites !== [])
            <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-4 space-y-3">
                <div class="flex items-center justify-between gap-2 flex-wrap">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Sites on this DA user</h3>
                    <p class="text-xs text-slate-500">{{ count($sites) }} domain(s) · {{ $addonSiteCount }} extra site(s) → sibling containers on this package</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-500 border-b border-slate-200 dark:border-slate-700">
                                <th class="py-2 pr-3">Domain</th>
                                <th class="py-2 pr-3">Stack</th>
                                <th class="py-2 pr-3">App root</th>
                                <th class="py-2 pr-3">Role</th>
                                <th class="py-2">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($sites as $site)
                                <tr>
                                    <td class="py-2 pr-3 font-mono text-xs break-all">{{ $site['domain'] ?? '—' }}</td>
                                    <td class="py-2 pr-3">{{ \App\Services\Provisioning\DirectAdminToContainerConvertService::stackLabel((string) ($site['stack'] ?? 'unknown')) }}</td>
                                    <td class="py-2 pr-3 font-mono text-xs break-all">{{ $site['app_root'] ?? $site['docroot'] ?? '—' }}</td>
                                    <td class="py-2 pr-3">
                                        @if ($site['is_primary'] ?? false)
                                            <span class="text-emerald-700 dark:text-emerald-300 font-medium">Primary (this convert)</span>
                                        @else
                                            <span class="text-amber-700 dark:text-amber-300">Extra site</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-xs text-slate-600 dark:text-slate-300">{{ $site['recommended_action'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($addonSiteCount > 0)
                    <p class="text-xs text-amber-800 dark:text-amber-200">
                        Policy: one Application Hosting package for this project. Each live site becomes its own container on that package (detected stack). Combined CPU, RAM, disk, and bandwidth above the package specs is billed as overage. Email stays on DirectAdmin.
                    </p>
                @endif
            </div>
        @endif

        @if (!empty($account['dashboard_error']))
            <p class="text-sm text-amber-700 dark:text-amber-300">Live DA dashboard: {{ $account['dashboard_error'] }}</p>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
            <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-3">
                <p class="text-xs uppercase text-slate-500">Disk</p>
                <p class="font-medium mt-1">
                    @if (!empty($account['disk']))
                        {{ number_format((float) ($account['disk']['used_mb'] ?? 0), 1) }} MB
                        @if (($account['disk']['limit_mb'] ?? null) !== null)
                            <span class="text-slate-500 font-normal">/ {{ number_format((float) $account['disk']['limit_mb'], 0) }} MB</span>
                        @endif
                    @elseif (!empty($account['package_usage_meta']['disk']))
                        {{ number_format((float) ($account['package_usage_meta']['disk']['used'] ?? 0), 1) }}
                        <span class="text-slate-500 font-normal text-xs">(cached)</span>
                    @else
                        —
                    @endif
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-3">
                <p class="text-xs uppercase text-slate-500">Bandwidth</p>
                <p class="font-medium mt-1">
                    @if (!empty($account['bandwidth']))
                        {{ number_format((float) ($account['bandwidth']['used_mb'] ?? 0), 1) }} MB
                        @if (($account['bandwidth']['limit_mb'] ?? null) !== null)
                            <span class="text-slate-500 font-normal">/ {{ number_format((float) $account['bandwidth']['limit_mb'], 0) }} MB</span>
                        @endif
                    @else
                        —
                    @endif
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-3">
                <p class="text-xs uppercase text-slate-500">Databases</p>
                <p class="font-medium mt-1">
                    {{ (int) ($account['counts']['database'] ?? count($inventory['databases'] ?? [])) }}
                    @if (!empty($account['counts']['database_limit']))
                        <span class="text-slate-500 font-normal">/ {{ (int) $account['counts']['database_limit'] }}</span>
                    @endif
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-3">
                <p class="text-xs uppercase text-slate-500">Email accounts</p>
                <p class="font-medium mt-1">
                    {{ (int) ($account['counts']['email'] ?? count($preflight['email']['all'] ?? [])) }}
                    @if (!empty($account['counts']['email_limit']))
                        <span class="text-slate-500 font-normal">/ {{ (int) $account['counts']['email_limit'] }}</span>
                    @endif
                </p>
            </div>
        </div>

        @if (!empty($inventory['databases']))
            <div>
                <h3 class="text-sm font-semibold mb-2">MySQL databases on DA</h3>
                <ul class="text-xs font-mono space-y-1 text-slate-700 dark:text-slate-300">
                    @foreach ($inventory['databases'] as $db)
                        <li>{{ $db['name'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (!empty($account['panel_url']))
            <p class="text-sm">
                Panel:
                <a href="{{ $account['panel_url'] }}" target="_blank" rel="noopener" class="text-indigo-600 dark:text-indigo-400 hover:underline font-mono text-xs">{{ $account['panel_url'] }}</a>
            </p>
        @endif

        @if (!empty($inventory['warnings']))
            <ul class="text-xs text-amber-800 dark:text-amber-200 space-y-1 list-disc list-inside">
                @foreach ($inventory['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($preflight)
        <div class="ui-card p-6 space-y-4">
            <h2 class="font-semibold text-lg">Email inventory</h2>
            @if ($daEmailCount > 0 && count($preflight['email']['all'] ?? []) === 0)
                <div class="rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-800 p-3 text-sm text-amber-900 dark:text-amber-100">
                    DirectAdmin usage reports <strong>{{ $daEmailCount }}</strong> email account(s), but POP/SSH inventory found <strong>0</strong> mailboxes.
                    Convert is blocked until mail is inventoried — mail would otherwise stay on DirectAdmin unnoticed.
                </div>
            @elseif ($daEmailCount > count($preflight['email']['all'] ?? []))
                <div class="rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-800 p-3 text-sm text-amber-900 dark:text-amber-100">
                    DirectAdmin usage shows <strong>{{ $daEmailCount }}</strong> email account(s); inventory found <strong>{{ count($preflight['email']['all'] ?? []) }}</strong>.
                    Confirm all mail domains before decommissioning DirectAdmin.
                </div>
            @endif
            @if (!($preflight['email']['success'] ?? false))
                <p class="text-sm text-red-600">{{ $preflight['email']['message'] ?? 'Failed' }}</p>
            @else
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    {{ count($preflight['email']['all'] ?? []) }} mailbox(es) across {{ count($mailboxByDomain) }} domain(s)
                    · default-style: {{ count($preflight['email']['default_mailboxes'] ?? []) }}
                    · extra: {{ count($preflight['email']['extra_mailboxes'] ?? []) }}
                </p>
                @if ($mailboxByDomain !== [])
                    <ul class="text-xs font-mono space-y-2 text-slate-600 dark:text-slate-300">
                        @foreach ($mailboxByDomain as $mailDomain => $boxes)
                            <li>
                                <span class="font-semibold">{{ $mailDomain }}</span>
                                — {{ collect($boxes)->pluck('email')->implode(', ') }}
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if (!empty($preflight['email']['errors']))
                    <ul class="text-xs text-amber-800 dark:text-amber-200 space-y-1">
                        @foreach ($preflight['email']['errors'] as $mailError)
                            <li>{{ $mailError }}</li>
                        @endforeach
                    </ul>
                @endif
                @if ($mustPullMail)
                    <p class="text-sm text-emerald-700 dark:text-emerald-300">
                        These mailboxes are pulled to Mailcow (IMAP sync). Update MX when sync has caught up, then DirectAdmin can be decommissioned.
                    </p>
                @else
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        @if ($daEmailCount > 0)
                            No mailboxes found via POP/SSH on the domains scanned (DA usage still shows {{ $daEmailCount }}).
                        @else
                            No mailboxes found on this DA user. Web convert does not need a Mailcow pull.
                        @endif
                    </p>
                @endif
            @endif

            @if ($emailInventoryWarnings !== [])
                <ul class="text-xs text-amber-800 dark:text-amber-200 space-y-1 list-disc list-inside">
                    @foreach ($emailInventoryWarnings as $inventoryWarning)
                        <li>{{ $inventoryWarning }}</li>
                    @endforeach
                </ul>
            @endif

            @if (!empty($preflight['blockers']))
                <div class="rounded-xl border border-red-200 bg-red-50 dark:bg-red-950/30 dark:border-red-800 p-4 text-sm text-red-800 dark:text-red-200 space-y-1">
                    @foreach ($preflight['blockers'] as $blocker)
                        <p>{{ $blocker }}</p>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('admin.services.migrate-to-container.store', $service) }}" class="ui-card p-6 space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-2">Application Hosting plan (billed when the DirectAdmin term ends)</label>
            <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">
                No charge today. Due date stays <strong>{{ $currentDue?->format('Y-m-d') ?? 'unchanged' }}</strong>
                ({{ $currentCycle }}). On that date, invoice generation creates a new unpaid invoice for the plan you select here — Application Hosting price, not the old DirectAdmin package.
                <code class="font-mono">custom_price</code> is cleared so the new product retail price is used.
            </p>
            @if ($products->isEmpty())
                <div class="rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950/30 p-4 text-sm text-amber-900 dark:text-amber-100 space-y-2">
                    <p>No Application Hosting products found in the catalog.</p>
                    <p>Create an active product with type <strong>Application Hosting</strong> and a container template.</p>
                    <a href="{{ route('admin.products.create') }}" class="inline-flex text-indigo-700 dark:text-indigo-300 underline">Create product</a>
                </div>
            @else
                @if ($productsAreFallback ?? false)
                    <p class="text-xs text-amber-700 dark:text-amber-300 mb-2">
                        No catalog item is tagged for {{ \App\Services\Provisioning\DirectAdminToContainerConvertService::stackLabel($detectedStack) }} — showing every Application Hosting plan. Pick the plan this customer should renew onto.
                    </p>
                @endif
                <select name="product_id" required class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2">
                    <option value="">Select Application Hosting plan…</option>
                    @if ($activeRecommended->isNotEmpty())
                        <optgroup label="Recommended for {{ \App\Services\Provisioning\DirectAdminToContainerConvertService::stackLabel($detectedStack) }}">
                            @foreach ($activeRecommended as $product)
                                <option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>
                                    {{ $product->name }}
                                    @if ($product->containerTemplate)
                                        · {{ $product->containerTemplate->name }}
                                    @endif
                                    — next invoice ≈ KES {{ number_format($productEstimates[$product->id] ?? 0, 0) }}
                                    / {{ $currentCycle }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if ($activeOther->isNotEmpty())
                        <optgroup label="All Application Hosting plans">
                            @foreach ($activeOther as $product)
                                <option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>
                                    {{ $product->name }}
                                    @if ($product->containerTemplate)
                                        · {{ $product->containerTemplate->name }}
                                    @endif
                                    — next invoice ≈ KES {{ number_format($productEstimates[$product->id] ?? 0, 0) }}
                                    / {{ $currentCycle }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if ($inactiveProducts->isNotEmpty())
                        <optgroup label="Inactive (activate before convert)">
                            @foreach ($inactiveProducts as $product)
                                <option value="{{ $product->id }}" disabled>
                                    {{ $product->name }} (inactive)
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
                <p class="text-xs text-slate-500 mt-2">
                    {{ $products->where('is_active', true)->count() }} active plan(s) listed.
                    Detected stack does not lock the plan — you dictate which product this service renews as.
                </p>
            @endif
        </div>

        @if (!empty($inventory['databases']))
            <div>
                <label class="block text-sm font-medium mb-2">Source database (optional)</label>
                <p class="text-xs text-slate-500 mb-2">
                    Used when wp-config / .env auto-detection fails, or for Node.js sites with MySQL on DirectAdmin.
                </p>
                <select name="database_name" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 font-mono text-sm">
                    <option value="">Auto from wp-config / .env / inventory</option>
                    @foreach ($inventory['databases'] as $db)
                        <option value="{{ $db['name'] }}">{{ $db['name'] }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($mustPullMail)
            <div>
                <label class="block text-sm font-medium mb-2">Email Hosting plan (Mailcow)</label>
                <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">
                    Mailboxes are created on Mailcow and IMAP-synced from DirectAdmin. Same due date as this service.
                    If the Application Hosting plan has a bundled email product, pick that plan (included / not a second billed package).
                </p>
                @if ($emailProducts->isEmpty())
                    <p class="text-sm text-red-600">No active Email Hosting products. Create one before converting — mail will not stay on DirectAdmin.</p>
                @else
                    <select name="email_product_id" required class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2">
                        <option value="">Select Email Hosting plan…</option>
                        @foreach ($emailProducts as $emailProduct)
                            <option value="{{ $emailProduct->id }}" @selected((string) old('email_product_id') === (string) $emailProduct->id)>
                                {{ $emailProduct->name }}
                            </option>
                        @endforeach
                    </select>
                @endif
            </div>
        @endif

        @if ($addonSiteCount > 0 || ($preflight['has_addon_sites'] ?? false))
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="acknowledge_addon_sites" value="1" class="mt-1 rounded border-slate-300" required>
                <span>I acknowledge extra live sites launch as sibling containers on this same Application Hosting package (not separately billed plans). Combined usage above package specs is billed as overage.</span>
            </label>
        @endif

        @if ($mustPullMail)
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="acknowledge_mail_pull" value="1" class="mt-1 rounded border-slate-300" required>
                <span>I acknowledge mail is pulled to Mailcow (IMAP sync from DirectAdmin). Update MX when sync has caught up, then DirectAdmin can be decommissioned. Customers must set Mailcow mailbox passwords (force password update).</span>
            </label>
        @endif

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="confirm_silent" value="1" class="mt-1 rounded border-slate-300" required @disabled(! $canConvert || $products->isEmpty())>
            <span>Confirm: silent admin convert — no invoice, no customer email/SMS, one service row.</span>
        </label>

        <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium disabled:opacity-50" @disabled(! $canConvert || $products->isEmpty())>
            Queue silent convert
        </button>
    </form>
</div>
@endsection
