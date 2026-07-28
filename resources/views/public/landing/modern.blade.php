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
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: {{ $branding['primary_color'] ?? '#0f766e' }};
            --brand-dark: color-mix(in srgb, var(--brand) 78%, #000);
        }
        body { font-family: 'DM Sans', system-ui, sans-serif; }
        .display { font-family: 'Fraunces', Georgia, serif; }
        .brand-text { color: var(--brand); }
        .brand-btn { background-color: var(--brand); }
        .brand-btn:hover { background-color: var(--brand-dark); }
        .brand-ring:focus { outline: none; box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 35%, transparent); }
        .hero-wash {
            background:
                radial-gradient(ellipse 80% 60% at 20% 0%, color-mix(in srgb, var(--brand) 18%, transparent), transparent 55%),
                radial-gradient(ellipse 70% 50% at 90% 10%, color-mix(in srgb, var(--brand) 12%, #bae6fd), transparent 50%),
                linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
        }
    </style>
</head>
<body class="bg-white text-slate-800 antialiased" x-data="storefrontPanel()">
    <header class="sticky top-0 z-40 bg-white/90 backdrop-blur border-b border-slate-200/80">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="flex items-center gap-3 min-w-0">
                @if (! empty($branding['logo_url']))
                    <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['company_name'] }}" class="h-9 w-auto max-w-[160px] object-contain">
                @else
                    <span class="display text-xl font-semibold brand-text truncate">{{ $branding['company_name'] }}</span>
                @endif
            </a>
            <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-600">
                @if ($landing['show_domains'])
                    <a href="#domains-pricing" class="hover:text-slate-900">Domains</a>
                @endif
                @if ($landing['show_hosting'])
                    <a href="#hosting" class="hover:text-slate-900">Hosting</a>
                @endif
                <a href="{{ $loginUrl }}" class="hover:text-slate-900">Log in</a>
                <a href="{{ $cartPageUrl ?? route('reseller.public.store.cart.show') }}" class="hover:text-slate-900">
                    Cart{{ ($cartCount ?? 0) > 0 ? " (".$cartCount.")" : "" }}
                </a>
                <a href="{{ $registerUrl }}" class="brand-btn text-white px-4 py-2 rounded-full shadow-sm">Get started</a>
            </nav>
            <a href="{{ $loginUrl }}" class="md:hidden text-sm font-semibold brand-text">Log in</a>
        </div>
    </header>

    <section id="home" class="hero-wash">
        <div class="max-w-6xl mx-auto px-4 pt-16 pb-20 sm:pt-24 sm:pb-28">
            <div class="max-w-3xl">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] brand-text mb-4">Hosting & domains</p>
                <h1 class="display text-4xl sm:text-5xl lg:text-6xl font-semibold tracking-tight text-slate-900 leading-[1.1]">
                    {{ $landing['hero_headline'] ?: ($branding['company_name'] ?? 'Launch with confidence') }}
                </h1>
                <p class="mt-5 text-lg text-slate-600 max-w-2xl">
                    {{ $landing['hero_subtext'] ?: ($branding['tagline'] ?: 'Search a domain, pick a plan, and check out in minutes.') }}
                </p>
            </div>

            @if ($landing['show_domains'])
                <form @submit.prevent="searchDomains()" class="mt-10 max-w-2xl">
                    <div class="flex flex-col sm:flex-row gap-3 p-2 rounded-2xl bg-white border border-slate-200 shadow-[0_20px_50px_-28px_rgba(15,23,42,0.35)]">
                        <input
                            type="text"
                            x-model="query"
                            placeholder="Search a domain name"
                            class="flex-1 px-4 py-3 rounded-xl text-slate-900 border-0 brand-ring"
                            autocomplete="off"
                        >
                        <button type="submit" :disabled="searching || query.trim().length < 2"
                            class="brand-btn text-white font-semibold px-6 py-3 rounded-xl disabled:opacity-60">
                            <span x-text="searching ? 'Searching…' : 'Search'"></span>
                        </button>
                    </div>
                    <p x-show="searchError" x-text="searchError" class="mt-3 text-sm text-rose-600"></p>
                </form>

                @if (count($extensions) > 0)
                    <div class="mt-8 flex flex-wrap gap-2">
                        @foreach (array_slice($extensions, 0, 8) as $ext)
                            <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-sm text-slate-700">
                                <span class="font-semibold">{{ $ext['extension'] }}</span>
                                <span class="text-slate-500">KES {{ number_format((float) $ext['price'], 0) }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </section>

    @include('public.landing.partials.domain-results', [
        'resultsClass' => 'bg-slate-50 border-y border-slate-200',
        'resultsHeadingClass' => 'display text-2xl font-semibold text-slate-900 mb-4',
        'resultsCardClass' => 'bg-white rounded-2xl border border-slate-200 overflow-hidden',
        'orderBtnClass' => 'brand-btn text-white text-sm font-semibold px-4 py-2 rounded-full disabled:opacity-60',
    ])

    @if ($landing['show_domains'] && count($extensions) > 0)
        <section id="domains-pricing" class="py-16 bg-white">
            <div class="max-w-6xl mx-auto px-4">
                <h2 class="display text-3xl font-semibold text-slate-900">Domain pricing</h2>
                <p class="mt-2 text-slate-600">Clear yearly rates for popular extensions.</p>
                <div class="mt-8 grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach ($extensions as $ext)
                        <div class="rounded-2xl border border-slate-200 p-5 hover:border-slate-300 transition">
                            <p class="text-lg font-semibold text-slate-900">{{ $ext['extension'] }}</p>
                            <p class="mt-2 text-2xl font-bold brand-text">KES {{ number_format((float) $ext['price'], 0) }}</p>
                            <p class="text-xs text-slate-500 mt-1">
                                Renew
                                @if ($ext['renewal_price'] !== null)
                                    KES {{ number_format((float) $ext['renewal_price'], 0) }}
                                @else
                                    —
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($landing['show_hosting'])
        <section id="hosting" class="py-16 bg-slate-50">
            <div class="max-w-6xl mx-auto px-4 space-y-12">
                <div>
                    <h2 class="display text-3xl font-semibold text-slate-900">Hosting plans</h2>
                    <p class="mt-2 text-slate-600">Choose a package and continue to checkout.</p>
                </div>
                @forelse ($serviceGroups as $group)
                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500 mb-4">{{ $group['label'] }}</h3>
                        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
                            @foreach ($group['products'] as $product)
                                <article class="bg-white rounded-2xl border border-slate-200 p-6 flex flex-col shadow-sm">
                                    <h4 class="text-xl font-semibold text-slate-900">{{ $product['name'] }}</h4>
                                    @if (! empty($product['description']))
                                        <p class="mt-2 text-sm text-slate-600">{{ \Illuminate\Support\Str::limit($product['description'], 120) }}</p>
                                    @endif
                                    <div class="mt-5">
                                        <span class="text-3xl font-bold text-slate-900">KES {{ number_format((float) ($product['monthly_price'] ?? 0), 0) }}</span>
                                        <span class="text-sm text-slate-500">/mo</span>
                                    </div>
                                    @if (! empty($product['features']) && is_array($product['features']))
                                        <ul class="mt-5 space-y-2 text-sm text-slate-700 flex-1">
                                            @foreach (array_slice($product['features'], 0, 5) as $feature)
                                                <li class="flex gap-2">
                                                    <span class="brand-text">✓</span>
                                                    <span>{{ is_array($feature) ? ($feature['label'] ?? $feature['name'] ?? json_encode($feature)) : $feature }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <div class="flex-1"></div>
                                    @endif
                                    <button type="button" @click="orderHosting({{ (int) $product['id'] }})" :disabled="ordering"
                                        class="mt-6 w-full brand-btn text-white font-semibold py-3 rounded-xl disabled:opacity-60">
                                        Order plan
                                    </button>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-slate-600">Hosting plans will appear here once added to the catalog.</p>
                @endforelse
            </div>
        </section>
    @endif

    <footer class="border-t border-slate-200 bg-white">
        <div class="max-w-6xl mx-auto px-4 py-10 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-8 text-sm">
            <div>
                <p class="display text-lg font-semibold text-slate-900">{{ $branding['company_name'] }}</p>
                @if (! empty($branding['footer_text']))
                    <p class="mt-2 text-slate-600 max-w-md">{{ $branding['footer_text'] }}</p>
                @elseif (! empty($branding['tagline']))
                    <p class="mt-2 text-slate-600">{{ $branding['tagline'] }}</p>
                @endif
            </div>
            <div class="space-y-1 text-slate-600">
                @if (! empty($branding['support_email']))
                    <a href="mailto:{{ $branding['support_email'] }}" class="block hover:text-slate-900">{{ $branding['support_email'] }}</a>
                @endif
                @if (! empty($branding['support_phone']))
                    <p>{{ $branding['support_phone'] }}</p>
                @endif
                <div class="flex gap-4 pt-2">
                    <a href="{{ $loginUrl }}" class="hover:text-slate-900">Client login</a>
                    <a href="{{ route('terms') }}" class="hover:text-slate-900">Terms</a>
                    <a href="{{ route('privacy') }}" class="hover:text-slate-900">Privacy</a>
                </div>
            </div>
        </div>
        <div class="border-t border-slate-100">
            <div class="max-w-6xl mx-auto px-4 py-4 text-xs text-slate-500">
                &copy; {{ date('Y') }} {{ $branding['company_name'] }}
            </div>
        </div>
    </footer>

    @include('public.landing.partials.storefront-script')
</body>
</html>
