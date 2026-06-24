@extends('layouts.admin')

@section('title', 'Developers')

@section('content')
@php
    $apiBase = $apiBaseUrl;
    $checkout = $checkoutUrl;
    $tokenPlaceholder = $plainTextToken ?? 'YOUR_API_TOKEN';
    $publicApiEnabled = old('public_api_enabled', $publicApiSettings['enabled'] ?? false);
    $publicApiOrigins = old('public_api_allowed_origins', implode("\n", $publicApiSettings['allowed_origins'] ?? []));
@endphp

<div class="space-y-8" x-data="developerDocs()">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Developers</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1 max-w-2xl">Power your main marketing website with domain search, platform services, and guest checkout via JSON API.</p>
        </div>
        @if($apiEnabled)
            <span class="inline-flex items-center gap-2 self-start px-3 py-1.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                API active on main site
            </span>
        @else
            <span class="inline-flex items-center gap-2 self-start px-3 py-1.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                Enable API below
            </span>
        @endif
    </div>

    @if (! $hostRecognized)
        <div class="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-xl p-4">
            <p class="text-sm text-amber-900 dark:text-amber-200">
                You are on <strong>{{ $requestHost }}</strong>, but the API is configured for
                <strong>{{ implode(', ', $platformHosts) }}</strong>.
                Update <a href="{{ route('admin.settings.index') }}" class="underline font-medium">Application URL</a>
                (<code class="text-xs">site_url</code>) to <strong>https://{{ $requestHost }}</strong> so docs, checkout links, and same-domain API calls match this server.
            </p>
        </div>
    @endif

    @if (session('success'))
        <div class="bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-xl p-4">
            <p class="text-sm text-emerald-800 dark:text-emerald-300">{{ session('success') }}</p>
        </div>
    @endif

    @if (session('error'))
        <div class="bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 rounded-xl p-4">
            <p class="text-sm text-red-800 dark:text-red-300">{{ session('error') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 rounded-xl p-4">
            <ul class="list-disc list-inside text-sm text-red-700 dark:text-red-400 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-5">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Website API settings</h2>

            <form method="POST" action="{{ route('admin.developers.settings.update') }}" class="space-y-4">
                @csrf
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="hidden" name="public_api_enabled" value="0">
                    <input type="checkbox" name="public_api_enabled" value="1" @checked($publicApiEnabled) class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <span>
                        <span class="block text-sm font-medium text-slate-800 dark:text-slate-200">Enable public website API</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400 mt-0.5">Exposes JSON endpoints on your main platform domain.</span>
                    </span>
                </label>
                <div>
                    <label for="public_api_allowed_origins" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Allowed website origins (optional)</label>
                    <textarea id="public_api_allowed_origins" name="public_api_allowed_origins" rows="3" placeholder="https://www.talksasa.com"
                        class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm font-mono">{{ $publicApiOrigins }}</textarea>
                    <p class="text-xs text-slate-500 mt-1">For cross-origin browser embeds from your marketing site. One origin per line.</p>
                </div>
                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">Save settings</button>
            </form>

            <div class="border-t border-slate-100 dark:border-slate-800 pt-5 space-y-4">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">API credentials</h3>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Base URL</label>
                    <p class="text-xs text-slate-500 mt-1">Served from your deployment URL (<code class="text-xs">APP_URL</code>). Use allowed origins below for your marketing site (e.g. <code class="text-xs">https://www.talksasa.com</code>).</p>
                    @if($portalUrlDiffers)
                        <p class="text-xs text-slate-500 mt-1">Application URL in <a href="{{ route('admin.settings.index') }}" class="underline">Settings</a> is <code class="text-xs">{{ $portalUrl }}</code> (branding/links). API calls still go to this base URL.</p>
                    @endif
                    <div class="mt-1.5 flex items-center gap-2">
                        <code class="flex-1 text-sm px-3 py-2.5 rounded-lg bg-slate-100 dark:bg-slate-800 font-mono break-all">{{ $apiBase }}</code>
                        <button type="button" @click="copy(@js($apiBase), 'base')" class="shrink-0 px-3 py-2 text-xs font-medium rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800" x-text="copied === 'base' ? 'Copied' : 'Copy'"></button>
                    </div>
                </div>

                @if($plainTextToken)
                    <div class="rounded-xl border-2 border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/30 p-4 space-y-2">
                        <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">API token</p>
                        <div class="flex items-center gap-2">
                            <code class="flex-1 text-xs sm:text-sm px-3 py-2.5 rounded-lg bg-white dark:bg-slate-900 font-mono break-all select-all">{{ $plainTextToken }}</code>
                            <button type="button" @click="copy(@js($plainTextToken), 'token')" class="shrink-0 px-3 py-2 text-xs font-medium rounded-lg bg-amber-600 hover:bg-amber-700 text-white" x-text="copied === 'token' ? 'Copied' : 'Copy'"></button>
                        </div>
                    </div>
                @elseif($tokenMetadata['exists'])
                    <div class="space-y-3">
                        <div>
                            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">API token</label>
                            <p class="mt-1.5 text-sm font-mono text-slate-700 dark:text-slate-300">••••••••••••{{ $tokenMetadata['hint'] ?? '····' }}</p>
                            <p class="text-xs text-slate-500 mt-1">
                                @if($tokenMetadata['admin_name']) Issued by {{ $tokenMetadata['admin_name'] }} · @endif
                                Created {{ $tokenMetadata['created_at'] ? \Carbon\Carbon::parse($tokenMetadata['created_at'])->diffForHumans() : 'recently' }}
                            </p>
                        </div>
                        <form method="POST" action="{{ route('admin.developers.token.reveal') }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <div class="flex-1 min-w-[12rem]">
                                <input type="password" name="password" required autocomplete="current-password" placeholder="Your password to reveal token"
                                    class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm @error('reveal_password') border-red-500 @enderror">
                                @error('reveal_password')
                                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <button type="submit" @if(! $apiEnabled) disabled @endif
                                class="px-4 py-2 rounded-lg text-sm font-medium {{ $apiEnabled ? 'border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800' : 'bg-slate-200 dark:bg-slate-800 text-slate-400 cursor-not-allowed' }}">
                                Reveal &amp; copy
                            </button>
                        </form>
                        @unless($tokenMetadata['copyable'] ?? false)
                            <p class="text-xs text-amber-700 dark:text-amber-300">This token was created before copy was supported. Regenerate once to enable copying later.</p>
                        @endunless
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.developers.token.regenerate') }}" class="space-y-3" onsubmit="return confirm('{{ $tokenMetadata['exists'] ? 'Regenerating will invalidate the current platform API token.' : 'Generate a new API token?' }}');">
                    @csrf
                    <input type="password" name="password" required autocomplete="current-password" placeholder="Confirm your password"
                        class="w-full max-w-sm px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm">
                    <button type="submit" @if(! $apiEnabled) disabled @endif
                        class="px-5 py-2.5 rounded-lg text-sm font-medium {{ $apiEnabled ? 'bg-blue-600 hover:bg-blue-700 text-white' : 'bg-slate-200 dark:bg-slate-800 text-slate-400 cursor-not-allowed' }}">
                        {{ $tokenMetadata['exists'] ? 'Regenerate token' : 'Generate token' }}
                    </button>
                </form>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Quick start</h2>
            <ol class="space-y-3 text-sm text-slate-600 dark:text-slate-400">
                <li class="flex gap-3"><span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 text-xs font-bold flex items-center justify-center shrink-0">1</span><span>Enable the API and save allowed origins if your marketing site is on a different domain.</span></li>
                <li class="flex gap-3"><span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 text-xs font-bold flex items-center justify-center shrink-0">2</span><span>Configure <strong class="text-slate-800 dark:text-slate-200">domain retail pricing</strong> and active <strong class="text-slate-800 dark:text-slate-200">products</strong>.</span></li>
                <li class="flex gap-3"><span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 text-xs font-bold flex items-center justify-center shrink-0">3</span><span>Generate an API token for server-side calls (optional for same-domain embeds).</span></li>
                <li class="flex gap-3"><span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 text-xs font-bold flex items-center justify-center shrink-0">4</span><span><code class="text-xs">POST /cart</code> then redirect to <code class="text-xs">{{ $checkout }}</code> for guest checkout.</span></li>
            </ol>
        </div>
    </div>

    <div class="flex flex-col xl:flex-row gap-8">
        <nav class="xl:w-56 shrink-0">
            <div class="xl:sticky xl:top-24 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 space-y-1">
                @foreach(['overview' => 'Overview', 'auth' => 'Authentication', 'domains' => 'Domains', 'services' => 'Services', 'reseller-packages' => 'Reseller plans', 'cart' => 'Checkout', 'examples' => 'Examples', 'errors' => 'Errors'] as $id => $label)
                    <button type="button" @click="scrollTo('{{ $id }}')" :class="active === '{{ $id }}' ? 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800'" class="w-full text-left px-3 py-2 rounded-lg text-sm font-medium transition">{{ $label }}</button>
                @endforeach
            </div>
        </nav>

        <div class="flex-1 min-w-0 space-y-10">
            <section id="overview" class="scroll-mt-24">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-3">Overview</h2>
                <p class="text-slate-600 dark:text-slate-400">Sell platform domains and hosting from your main website. Prices returned are <strong class="text-slate-800 dark:text-slate-200">retail</strong> rates configured in admin.</p>
            </section>

            <section id="auth" class="scroll-mt-24">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-3">Authentication</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-3">Same-domain requests need no token. Server-side or cross-origin calls use a bearer token from this page.</p>
                @include('reseller.developers.partials.code-block', ['id' => 'auth-h', 'code' => "Authorization: Bearer {$tokenPlaceholder}\nAccept: application/json"])
            </section>

            <section id="domains" class="scroll-mt-24 space-y-6">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Domains</h2>
                <div class="border border-slate-200 dark:border-slate-700 rounded-2xl overflow-hidden">
                    <div class="px-5 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-700">
                        <span class="text-xs font-bold bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded">GET</span>
                        <code class="ml-2 text-sm font-mono">/domains/search?q=acme&period=1</code>
                    </div>
                    <div class="p-5">@include('reseller.developers.partials.code-block', ['id' => 'ds', 'code' => "GET {$apiBase}/domains/search?q=acme"])</div>
                </div>
                <div class="border border-slate-200 dark:border-slate-700 rounded-2xl overflow-hidden">
                    <div class="px-5 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-700">
                        <span class="text-xs font-bold bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded">GET</span>
                        <code class="ml-2 text-sm font-mono">/domains/extensions</code>
                    </div>
                    <div class="p-5">@include('reseller.developers.partials.code-block', ['id' => 'de', 'code' => "GET {$apiBase}/domains/extensions?period=1"])</div>
                </div>
            </section>

            <section id="services" class="scroll-mt-24">
                <div class="border border-slate-200 dark:border-slate-700 rounded-2xl overflow-hidden">
                    <div class="px-5 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-700">
                        <span class="text-xs font-bold bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded">GET</span>
                        <code class="ml-2 text-sm font-mono">/services</code>
                    </div>
                    <div class="p-5 space-y-2">
                        <p class="text-sm text-slate-600 dark:text-slate-400">Lists all active platform products with retail pricing. VPS and dedicated server products include a <code class="text-xs">configuration</code> object with specs, datacenter locations (regional pricing), IP options, and allowed operating systems.</p>
                        @include('reseller.developers.partials.code-block', ['id' => 'sv', 'code' => "GET {$apiBase}/services"])
                    </div>
                </div>
            </section>

            <section id="reseller-packages" class="scroll-mt-24">
                <div class="border border-slate-200 dark:border-slate-700 rounded-2xl overflow-hidden">
                    <div class="px-5 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-700">
                        <span class="text-xs font-bold bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded">GET</span>
                        <code class="ml-2 text-sm font-mono">/reseller-packages?cycle=monthly</code>
                    </div>
                    <div class="p-5 space-y-2">
                        <p class="text-sm text-slate-600 dark:text-slate-400">Lists active platform reseller plans (monthly or annually). Each package includes limits, tax-inclusive totals, and feature summary. Platform host only.</p>
                        @include('reseller.developers.partials.code-block', ['id' => 'rp', 'code' => "GET {$apiBase}/reseller-packages?cycle=monthly"])
                    </div>
                </div>
            </section>

            <section id="cart" class="scroll-mt-24">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-3">Checkout</h2>
                @include('reseller.developers.partials.code-block', ['id' => 'cart', 'code' => json_encode([
                    'items' => [
                        ['type' => 'domain', 'full_domain' => 'acme.com', 'years' => 1],
                        ['type' => 'service', 'product_id' => 3, 'billing_cycle' => 'annual'],
                        ['type' => 'reseller_package', 'reseller_package_id' => 2],
                        [
                            'type' => 'service',
                            'product_id' => 12,
                            'billing_cycle' => 'monthly',
                            'location_key' => 'usa',
                            'ip_count' => 2,
                            'operating_system' => 'ubuntu-24.04',
                        ],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)])
                <p class="text-sm text-slate-500 mt-2">POST to <code class="text-xs">{{ $apiBase }}/cart</code> → redirect to <code class="text-xs">{{ $checkout }}</code>. For VPS/dedicated servers, include <code class="text-xs">location_key</code>, <code class="text-xs">ip_count</code>, and <code class="text-xs">operating_system</code>. Reseller plans use <code class="text-xs">type: reseller_package</code> and must be checked out alone.</p>
            </section>

            <section id="examples" class="scroll-mt-24">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-3">Examples</h2>
                @include('reseller.developers.partials.code-block', ['id' => 'curl', 'code' => "curl -s -H \"Authorization: Bearer {$tokenPlaceholder}\" \\\n  \"{$apiBase}/services\""])
            </section>

            <section id="errors" class="scroll-mt-24">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-3">Errors</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400">404 — API disabled or wrong host · 403 — not enabled · 422 — invalid cart · 429 — rate limited</p>
            </section>
        </div>
    </div>
</div>

<script>
function developerDocs() {
    return {
        active: 'overview',
        copied: null,
        scrollTo(id) { this.active = id; document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }); },
        async copy(text, key) {
            try { await navigator.clipboard.writeText(text); this.copied = key; setTimeout(() => { if (this.copied === key) this.copied = null; }, 2000); } catch (e) { alert('Copy failed'); }
        },
        init() {
            ['overview','auth','domains','services','reseller-packages','cart','examples','errors'].forEach(id => {
                const el = document.getElementById(id);
                if (el) new IntersectionObserver((entries) => { entries.forEach(e => { if (e.isIntersecting) this.active = e.target.id; }); }, { rootMargin: '-20% 0px -60% 0px' }).observe(el);
            });
        }
    };
}
</script>
@endsection
