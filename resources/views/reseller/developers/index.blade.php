@extends('layouts.reseller')

@section('title', 'Developers')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Dashboard</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Developers</p>
</div>
@endsection

@section('content')
@php
    $apiBase = $apiBaseUrl ?? 'https://your-domain.com/api/v1/public';
    $checkout = $checkoutUrl ?? 'https://your-domain.com/checkout';
    $tokenPlaceholder = $plainTextToken ?? 'YOUR_API_TOKEN';
@endphp

<div class="space-y-8" x-data="developerDocs()">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Developers</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1 max-w-2xl">Connect your website to domain search, service catalog, and checkout using the public JSON API.</p>
        </div>
        @if($apiEnabled && $apiBaseUrl)
            <span class="inline-flex items-center gap-2 self-start px-3 py-1.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                API active
            </span>
        @else
            <span class="inline-flex items-center gap-2 self-start px-3 py-1.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                Setup required
            </span>
        @endif
    </div>

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

    {{-- Credentials --}}
    <div class="grid lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">API credentials</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Use your branding domain and bearer token for server-side or cross-origin integrations.</p>
                </div>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Base URL</label>
                    <div class="mt-1.5 flex items-center gap-2">
                        <code class="flex-1 text-sm px-3 py-2.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-mono break-all">{{ $apiBaseUrl ?? 'Configure custom domain in Settings → Branding' }}</code>
                        @if($apiBaseUrl)
                            <button type="button" @click="copy(@js($apiBaseUrl), 'base')" class="shrink-0 px-3 py-2 text-xs font-medium rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 transition" x-text="copied === 'base' ? 'Copied' : 'Copy'"></button>
                        @endif
                    </div>
                </div>

                @if($plainTextToken)
                    <div class="rounded-xl border-2 border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/30 p-4 space-y-2">
                        <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">API token</p>
                        <div class="flex items-center gap-2">
                            <code class="flex-1 text-xs sm:text-sm px-3 py-2.5 rounded-lg bg-white dark:bg-slate-900 font-mono text-slate-900 dark:text-white break-all select-all">{{ $plainTextToken }}</code>
                            <button type="button" @click="copy(@js($plainTextToken), 'token')" class="shrink-0 px-3 py-2 text-xs font-medium rounded-lg bg-amber-600 hover:bg-amber-700 text-white transition" x-text="copied === 'token' ? 'Copied' : 'Copy'"></button>
                        </div>
                    </div>
                @elseif($tokenMetadata['exists'])
                    <div class="space-y-3">
                        <div>
                            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">API token</label>
                            <p class="mt-1.5 text-sm font-mono text-slate-700 dark:text-slate-300">••••••••••••{{ $tokenMetadata['hint'] ?? '····' }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                Created {{ $tokenMetadata['created_at'] ? \Carbon\Carbon::parse($tokenMetadata['created_at'])->diffForHumans() : 'recently' }}
                                @if($tokenMetadata['last_used_at'])
                                    · Last used {{ \Carbon\Carbon::parse($tokenMetadata['last_used_at'])->diffForHumans() }}
                                @endif
                            </p>
                        </div>
                        <form method="POST" action="{{ route('reseller.developers.token.reveal') }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <div class="flex-1 min-w-[12rem]">
                                <input type="password" name="password" required autocomplete="current-password" placeholder="Your password to reveal token"
                                    class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm text-slate-900 dark:text-white @error('reveal_password') border-red-500 @enderror">
                                @error('reveal_password')
                                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <button type="submit" @if(! $apiEnabled) disabled @endif
                                class="px-4 py-2 rounded-lg text-sm font-medium transition {{ $apiEnabled ? 'border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300' : 'bg-slate-200 dark:bg-slate-800 text-slate-400 cursor-not-allowed' }}">
                                Reveal &amp; copy
                            </button>
                        </form>
                        @unless($tokenMetadata['copyable'] ?? false)
                            <p class="text-xs text-amber-700 dark:text-amber-300">This token was created before copy was supported. Regenerate once to enable copying later.</p>
                        @endunless
                    </div>
                @else
                    <p class="text-sm text-slate-500 dark:text-slate-400">No API token yet. Generate one to authenticate requests with <code class="text-xs">Authorization: Bearer</code>.</p>
                @endif

                <form method="POST" action="{{ route('reseller.developers.token.regenerate') }}" class="space-y-3 pt-2 border-t border-slate-100 dark:border-slate-800" onsubmit="return confirm('{{ $tokenMetadata['exists'] ? 'Regenerating will invalidate your current token immediately.' : 'Generate a new API token?' }}');">
                    @csrf
                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Confirm your password</label>
                        <input type="password" id="password" name="password" required autocomplete="current-password"
                            class="w-full max-w-sm px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500">
                    </div>
                    <button type="submit" @if(! $apiEnabled) disabled @endif
                        class="px-5 py-2.5 rounded-lg text-sm font-medium transition {{ $apiEnabled ? 'bg-purple-600 hover:bg-purple-700 text-white' : 'bg-slate-200 dark:bg-slate-800 text-slate-400 cursor-not-allowed' }}">
                        {{ $tokenMetadata['exists'] ? 'Regenerate token' : 'Generate token' }}
                    </button>
                    @unless($apiEnabled)
                        <p class="text-xs text-amber-700 dark:text-amber-300">Enable the API in <a href="{{ route('reseller.settings.index', ['tab' => 'branding']) }}" class="underline font-medium">Settings → Branding</a> first.</p>
                    @endunless
                </form>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Quick start</h2>
            <ol class="space-y-3 text-sm text-slate-600 dark:text-slate-400">
                <li class="flex gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300 text-xs font-bold flex items-center justify-center">1</span>
                    <span>Set your <strong class="text-slate-800 dark:text-slate-200">custom domain</strong> and enable the public website API in branding settings.</span>
                </li>
                <li class="flex gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300 text-xs font-bold flex items-center justify-center">2</span>
                    <span>Configure <strong class="text-slate-800 dark:text-slate-200">domain pricing</strong> and <strong class="text-slate-800 dark:text-slate-200">catalog</strong> — only enabled TLDs and active products are exposed.</span>
                </li>
                <li class="flex gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300 text-xs font-bold flex items-center justify-center">3</span>
                    <span>Generate an API token (optional for same-domain embeds; required for server-side calls).</span>
                </li>
                <li class="flex gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300 text-xs font-bold flex items-center justify-center">4</span>
                    <span>Search domains or list services → <code class="text-xs">POST /cart</code> → redirect visitors to checkout.</span>
                </li>
            </ol>
            @if(!empty($publicApiSettings['allowed_origins']))
                <div class="pt-3 border-t border-slate-100 dark:border-slate-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">CORS origins</p>
                    <ul class="text-xs font-mono text-slate-600 dark:text-slate-400 space-y-1">
                        @foreach($publicApiSettings['allowed_origins'] as $origin)
                            <li>{{ $origin }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    {{-- Documentation --}}
    <div class="flex flex-col xl:flex-row gap-8">
        <nav class="xl:w-56 shrink-0">
            <div class="xl:sticky xl:top-24 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 space-y-1">
                <p class="px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Documentation</p>
                @foreach(['overview' => 'Overview', 'auth' => 'Authentication', 'domains' => 'Domains', 'services' => 'Services', 'cart' => 'Checkout flow', 'examples' => 'Code examples', 'errors' => 'Errors'] as $id => $label)
                    <button type="button" @click="scrollTo('{{ $id }}')"
                        :class="active === '{{ $id }}' ? 'bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800'"
                        class="w-full text-left px-3 py-2 rounded-lg text-sm font-medium transition">{{ $label }}</button>
                @endforeach
            </div>
        </nav>

        <div class="flex-1 min-w-0 space-y-10 doc-content">
            <section id="overview" class="scroll-mt-24">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-3">Overview</h2>
                <p class="text-slate-600 dark:text-slate-400 leading-relaxed">The Website Sales API lets you sell domains and hosting from your own frontend. All prices returned are your <strong class="text-slate-800 dark:text-slate-200">retail</strong> rates. Wholesale costs and registrar internals are never exposed.</p>
                <div class="mt-4 grid sm:grid-cols-2 gap-3">
                    <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700">
                        <p class="text-xs font-semibold text-purple-600 dark:text-purple-400 uppercase">Rate limit</p>
                        <p class="text-sm text-slate-700 dark:text-slate-300 mt-1">30 requests / minute per IP</p>
                    </div>
                    <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700">
                        <p class="text-xs font-semibold text-purple-600 dark:text-purple-400 uppercase">Format</p>
                        <p class="text-sm text-slate-700 dark:text-slate-300 mt-1">JSON · UTF-8</p>
                    </div>
                </div>
            </section>

            <section id="auth" class="scroll-mt-24">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-3">Authentication</h2>
                <p class="text-slate-600 dark:text-slate-400 mb-4">Two supported modes:</p>
                <div class="space-y-4">
                    <div class="p-5 rounded-xl border border-slate-200 dark:border-slate-700">
                        <h3 class="font-semibold text-slate-900 dark:text-white">Same-domain (browser embed)</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2">Host your widget on the same custom domain as the API. No token required — the server identifies your account from the hostname.</p>
                    </div>
                    <div class="p-5 rounded-xl border border-slate-200 dark:border-slate-700">
                        <h3 class="font-semibold text-slate-900 dark:text-white">Bearer token (server-side)</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 mb-3">Send your API token on every request:</p>
                        @include('reseller.developers.partials.code-block', ['id' => 'auth-header', 'code' => "Authorization: Bearer {$tokenPlaceholder}\nAccept: application/json"])
                    </div>
                </div>
            </section>

            <section id="domains" class="scroll-mt-24 space-y-8">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-3">Domains</h2>
                    <p class="text-slate-600 dark:text-slate-400">Search availability with retail pricing. Bare-name queries (e.g. <code class="text-sm">acme</code>) check every TLD you have enabled in Domain Pricing.</p>
                </div>

                <div class="border border-slate-200 dark:border-slate-700 rounded-2xl overflow-hidden">
                    <div class="px-5 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between gap-4">
                        <div>
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200">GET</span>
                            <code class="ml-2 text-sm font-mono text-slate-800 dark:text-slate-200">/domains/search</code>
                        </div>
                    </div>
                    <div class="p-5 space-y-4">
                        <table class="w-full text-sm">
                            <thead><tr class="text-left text-slate-500"><th class="pb-2 pr-4">Param</th><th class="pb-2 pr-4">Required</th><th class="pb-2">Description</th></tr></thead>
                            <tbody class="text-slate-700 dark:text-slate-300">
                                <tr><td class="py-1.5 font-mono text-purple-600 dark:text-purple-400">q</td><td>Yes</td><td>Label or FQDN</td></tr>
                                <tr><td class="py-1.5 font-mono text-purple-600 dark:text-purple-400">period</td><td>No</td><td>Registration years (default 1)</td></tr>
                            </tbody>
                        </table>
                        @include('reseller.developers.partials.code-block', ['id' => 'domains-search-req', 'code' => "GET {$apiBase}/domains/search?q=acme&period=1"])
                        @include('reseller.developers.partials.code-block', ['id' => 'domains-search-res', 'code' => json_encode([
                            'success' => true,
                            'query' => 'acme',
                            'period_years' => 1,
                            'currency' => 'KES',
                            'checkout_url' => $checkout,
                            'results' => [[
                                'domain' => 'acme',
                                'extension' => '.com',
                                'full_domain' => 'acme.com',
                                'available' => true,
                                'period_years' => 1,
                                'price' => 2299,
                                'currency' => 'KES',
                                'checkout_url' => $checkout,
                            ]],
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)])
                    </div>
                </div>

                <div class="border border-slate-200 dark:border-slate-700 rounded-2xl overflow-hidden">
                    <div class="px-5 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-700">
                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200">GET</span>
                        <code class="ml-2 text-sm font-mono">/domains/extensions</code>
                    </div>
                    <div class="p-5">
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-3">List sellable TLDs and prices without running availability checks.</p>
                        @include('reseller.developers.partials.code-block', ['id' => 'domains-ext', 'code' => "GET {$apiBase}/domains/extensions?period=1"])
                    </div>
                </div>
            </section>

            <section id="services" class="scroll-mt-24">
                <div class="border border-slate-200 dark:border-slate-700 rounded-2xl overflow-hidden">
                    <div class="px-5 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-700">
                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200">GET</span>
                        <code class="ml-2 text-sm font-mono">/services</code>
                    </div>
                    <div class="p-5 space-y-3">
                        <p class="text-sm text-slate-600 dark:text-slate-400">Returns active, orderable items from your catalog with monthly/yearly retail prices.</p>
                        @include('reseller.developers.partials.code-block', ['id' => 'services-req', 'code' => "GET {$apiBase}/services"])
                    </div>
                </div>
            </section>

            <section id="cart" class="scroll-mt-24">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-3">Checkout flow</h2>
                <p class="text-slate-600 dark:text-slate-400 mb-4">Validate items server-side, store the cart in session, and redirect the visitor to guest checkout where they can create an account before payment.</p>
                <div class="border border-slate-200 dark:border-slate-700 rounded-2xl overflow-hidden">
                    <div class="px-5 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-700">
                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-bold bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">POST</span>
                        <code class="ml-2 text-sm font-mono">/cart</code>
                    </div>
                    <div class="p-5 space-y-4">
                        @include('reseller.developers.partials.code-block', ['id' => 'cart-body', 'code' => json_encode([
                            'items' => [
                                ['type' => 'domain', 'full_domain' => 'acme.com', 'years' => 1],
                                ['type' => 'service', 'reseller_product_id' => 12, 'billing_cycle' => 'annual'],
                            ],
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)])
                        <p class="text-sm text-slate-500">Response includes <code class="text-xs">checkout_url</code> → <code class="text-xs">{{ $checkout }}</code></p>
                    </div>
                </div>
            </section>

            <section id="examples" class="scroll-mt-24 space-y-6">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Code examples</h2>

                <div>
                    <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-2">cURL</h3>
                    @include('reseller.developers.partials.code-block', ['id' => 'ex-curl', 'code' => "curl -s -H \"Authorization: Bearer {$tokenPlaceholder}\" \\\n  \"{$apiBase}/domains/search?q=acme\""])
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-2">JavaScript (same domain)</h3>
                    @include('reseller.developers.partials.code-block', ['id' => 'ex-js', 'code' => "const res = await fetch('/api/v1/public/domains/search?q=acme');\nconst data = await res.json();\n\n// Add to cart and checkout\nconst cart = await fetch('/api/v1/public/cart', {\n  method: 'POST',\n  headers: { 'Content-Type': 'application/json' },\n  body: JSON.stringify({\n    items: [{ type: 'domain', full_domain: 'acme.com', years: 1 }]\n  })\n});\nconst { checkout_url } = await cart.json();\nwindow.location.href = checkout_url;"])
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-2">PHP</h3>
                    @include('reseller.developers.partials.code-block', ['id' => 'ex-php', 'code' => "\$ch = curl_init('{$apiBase}/services');\ncurl_setopt_array(\$ch, [\n  CURLOPT_HTTPHEADER => [\n    'Authorization: Bearer {$tokenPlaceholder}',\n    'Accept: application/json',\n  ],\n  CURLOPT_RETURNTRANSFER => true,\n]);\n\$body = curl_exec(\$ch);\n\$services = json_decode(\$body, true)['services'] ?? [];"])
                </div>
            </section>

            <section id="errors" class="scroll-mt-24">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-3">HTTP errors</h2>
                <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/80 text-left">
                            <tr><th class="px-4 py-3 font-semibold text-slate-700 dark:text-slate-300">Status</th><th class="px-4 py-3 font-semibold text-slate-700 dark:text-slate-300">Meaning</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-600 dark:text-slate-400">
                            <tr><td class="px-4 py-3 font-mono">404</td><td class="px-4 py-3">Unknown host or invalid token context</td></tr>
                            <tr><td class="px-4 py-3 font-mono">403</td><td class="px-4 py-3">Public API not enabled</td></tr>
                            <tr><td class="px-4 py-3 font-mono">422</td><td class="px-4 py-3">Validation or cart item rejected</td></tr>
                            <tr><td class="px-4 py-3 font-mono">429</td><td class="px-4 py-3">Rate limit exceeded</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
function developerDocs() {
    return {
        active: 'overview',
        copied: null,
        scrollTo(id) {
            this.active = id;
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        async copy(text, key) {
            try {
                await navigator.clipboard.writeText(text);
                this.copied = key;
                setTimeout(() => { if (this.copied === key) this.copied = null; }, 2000);
            } catch (e) {
                alert('Copy failed');
            }
        },
        init() {
            const sections = ['overview', 'auth', 'domains', 'services', 'cart', 'examples', 'errors'];
            const observer = new IntersectionObserver((entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        this.active = entry.target.id;
                    }
                }
            }, { rootMargin: '-20% 0px -60% 0px', threshold: 0 });
            sections.forEach(id => {
                const el = document.getElementById(id);
                if (el) observer.observe(el);
            });
        }
    };
}
</script>
@endsection
