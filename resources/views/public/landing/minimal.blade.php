<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $branding['company_name'] ?? config('app.name') }}</title>
    @if (! empty($branding['favicon_url']))
        <link rel="icon" href="{{ $branding['favicon_url'] }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: {{ $branding['primary_color'] ?? '#111827' }};
            --brand-dark: color-mix(in srgb, var(--brand) 85%, #000);
        }
        body { font-family: 'IBM Plex Sans', system-ui, sans-serif; }
        .brand-text { color: var(--brand); }
        .brand-btn { background-color: var(--brand); color: #fff; }
        .brand-btn:hover { background-color: var(--brand-dark); }
        .brand-border { border-color: var(--brand); }
    </style>
</head>
<body class="bg-white text-slate-900 antialiased" x-data="storefrontPanel()">
    <header class="border-b border-slate-200">
        <div class="max-w-3xl mx-auto px-4 py-5 flex items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="min-w-0">
                @if (! empty($branding['logo_url']))
                    <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['company_name'] }}" class="h-8 w-auto max-w-[140px] object-contain">
                @else
                    <span class="font-semibold tracking-tight truncate">{{ $branding['company_name'] }}</span>
                @endif
            </a>
            <div class="flex items-center gap-4 text-sm">
                <a href="{{ $cartPageUrl ?? route('reseller.public.store.cart.show') }}" class="text-slate-600 hover:text-slate-900">
                    Cart{{ ($cartCount ?? 0) > 0 ? " (".$cartCount.")" : "" }}
                </a>
                <a href="{{ $loginUrl }}" class="text-slate-600 hover:text-slate-900">Login</a>
                <a href="{{ $registerUrl }}" class="font-medium brand-text">Register</a>
            </div>
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-4 py-16 sm:py-24">
        <section id="home" class="text-center">
            <h1 class="text-3xl sm:text-4xl font-semibold tracking-tight">
                {{ $landing['hero_headline'] ?: ($branding['company_name'] ?? 'Find a domain') }}
            </h1>
            <p class="mt-3 text-slate-600">
                {{ $landing['hero_subtext'] ?: ($branding['tagline'] ?: 'Search, order, and manage everything from your client area.') }}
            </p>

            @if ($landing['show_domains'])
                <form @submit.prevent="searchDomains()" class="mt-10">
                    <div class="flex flex-col sm:flex-row border-2 brand-border rounded-lg overflow-hidden">
                        <input
                            type="text"
                            x-model="query"
                            placeholder="example.com"
                            class="flex-1 px-4 py-3.5 text-center sm:text-left focus:outline-none"
                            autocomplete="off"
                        >
                        <button type="submit" :disabled="searching || query.trim().length < 2"
                            class="brand-btn font-semibold px-6 py-3.5 disabled:opacity-60">
                            <span x-text="searching ? '…' : 'Search'"></span>
                        </button>
                    </div>
                    <p x-show="searchError" x-text="searchError" class="mt-3 text-sm text-rose-600"></p>
                </form>

                @if (count($extensions) > 0)
                    <div class="mt-6 flex flex-wrap justify-center gap-x-5 gap-y-2 text-sm text-slate-600">
                        @foreach (array_slice($extensions, 0, 10) as $ext)
                            <span><strong class="text-slate-900">{{ $ext['extension'] }}</strong> KES {{ number_format((float) $ext['price'], 0) }}</span>
                        @endforeach
                    </div>
                @endif
            @endif
        </section>

        @include('public.landing.partials.domain-results', [
            'resultsClass' => 'mt-12',
            'resultsContainerClass' => 'text-left',
            'resultsHeadingClass' => 'text-lg font-semibold mb-3',
            'resultsCardClass' => 'border border-slate-200 rounded-lg overflow-hidden',
            'orderBtnClass' => 'brand-btn text-sm font-semibold px-3 py-1.5 rounded disabled:opacity-60',
        ])

        @if ($landing['show_domains'] && count($extensions) > 0)
            <section id="domains-pricing" class="mt-16 text-left">
                <h2 class="text-lg font-semibold">Domain prices</h2>
                <div class="mt-4 divide-y divide-slate-200 border-y border-slate-200">
                    @foreach ($extensions as $ext)
                        <div class="py-3 flex items-center justify-between gap-4 text-sm">
                            <span class="font-medium">{{ $ext['extension'] }}</span>
                            <span>KES {{ number_format((float) $ext['price'], 2) }}/yr</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($landing['show_hosting'])
            <section id="hosting" class="mt-16 text-left">
                <h2 class="text-lg font-semibold">Hosting</h2>
                @forelse ($serviceGroups as $group)
                    <div class="mt-6">
                        <p class="text-xs uppercase tracking-wider text-slate-500 mb-3">{{ $group['label'] }}</p>
                        <div class="space-y-3">
                            @foreach ($group['products'] as $product)
                                <div class="border border-slate-200 rounded-lg p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                    <div>
                                        <p class="font-semibold">{{ $product['name'] }}</p>
                                        <p class="text-sm text-slate-600 mt-0.5">
                                            KES {{ number_format((float) ($product['monthly_price'] ?? 0), 0) }}/mo
                                            @if (! empty($product['yearly_price']))
                                                · KES {{ number_format((float) $product['yearly_price'], 0) }}/yr
                                            @endif
                                        </p>
                                    </div>
                                    <button type="button" @click="orderHosting({{ (int) $product['id'] }})" :disabled="ordering"
                                        class="brand-btn text-sm font-semibold px-4 py-2 rounded disabled:opacity-60 shrink-0">
                                        Order
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="mt-4 text-sm text-slate-600">No hosting plans listed yet.</p>
                @endforelse
            </section>
        @endif
    </main>

    <footer class="border-t border-slate-200">
        <div class="max-w-3xl mx-auto px-4 py-8 text-sm text-slate-600 flex flex-col sm:flex-row sm:justify-between gap-3">
            <span>&copy; {{ date('Y') }} {{ $branding['company_name'] }}</span>
            <div class="flex gap-4">
                @if (! empty($branding['support_email']))
                    <a href="mailto:{{ $branding['support_email'] }}" class="hover:text-slate-900">Support</a>
                @endif
                <a href="{{ $loginUrl }}" class="hover:text-slate-900">Client area</a>
                <a href="{{ route('terms') }}" class="hover:text-slate-900">Terms</a>
            </div>
        </div>
    </footer>

    @include('public.landing.partials.storefront-script')
</body>
</html>
