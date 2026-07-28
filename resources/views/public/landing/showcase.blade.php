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
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,500;8..60,600&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: {{ $branding['primary_color'] ?? '#f59e0b' }};
            --brand-dark: color-mix(in srgb, var(--brand) 75%, #000);
            --ink: #0c1222;
            --panel: #121a2b;
        }
        body { font-family: 'Space Grotesk', system-ui, sans-serif; background: var(--ink); color: #e2e8f0; }
        .serif { font-family: 'Source Serif 4', Georgia, serif; }
        .brand-text { color: var(--brand); }
        .brand-btn { background-color: var(--brand); color: #0c1222; }
        .brand-btn:hover { background-color: color-mix(in srgb, var(--brand) 88%, #fff); }
        .panel { background: var(--panel); }
        .hero-grid {
            background-image:
                linear-gradient(rgba(255,255,255,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px);
            background-size: 48px 48px;
            background-position: center top;
        }
        .glow {
            background: radial-gradient(ellipse 60% 40% at 50% 0%, color-mix(in srgb, var(--brand) 28%, transparent), transparent 70%);
        }
    </style>
</head>
<body class="antialiased" x-data="storefrontPanel()">
    <div class="hero-grid glow min-h-screen">
        <header class="border-b border-white/10">
            <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between gap-4">
                <a href="{{ route('home') }}" class="flex items-center gap-3 min-w-0">
                    @if (! empty($branding['logo_url']))
                        <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['company_name'] }}" class="h-9 w-auto max-w-[160px] object-contain">
                    @else
                        <span class="text-lg font-semibold tracking-tight truncate">{{ $branding['company_name'] }}</span>
                    @endif
                </a>
                <nav class="hidden md:flex items-center gap-6 text-sm text-slate-300">
                    @if ($landing['show_domains'])
                        <a href="#domains-pricing" class="hover:text-white">Domains</a>
                    @endif
                    @if ($landing['show_hosting'])
                        <a href="#hosting" class="hover:text-white">Plans</a>
                    @endif
                    <a href="{{ $loginUrl }}" class="hover:text-white">Client login</a>
                    <a href="{{ $cartPageUrl ?? route('reseller.public.store.cart.show') }}" class="hover:text-white">
                        Cart{{ ($cartCount ?? 0) > 0 ? " (".$cartCount.")" : "" }}
                    </a>
                    <a href="{{ $registerUrl }}" class="brand-btn font-semibold px-4 py-2 rounded-md">Register</a>
                </nav>
                <a href="{{ $loginUrl }}" class="md:hidden text-sm brand-text font-semibold">Login</a>
            </div>
        </header>

        <section id="home" class="max-w-6xl mx-auto px-4 pt-16 pb-14 sm:pt-24">
            <div class="max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] brand-text mb-4">Storefront</p>
                <h1 class="serif text-4xl sm:text-5xl lg:text-6xl font-semibold text-white leading-tight">
                    {{ $landing['hero_headline'] ?: ($branding['company_name'] ?? 'Own the web') }}
                </h1>
                <p class="mt-5 text-lg text-slate-300 max-w-xl">
                    {{ $landing['hero_subtext'] ?: ($branding['tagline'] ?: 'Domains and hosting with transparent pricing — checkout in a few clicks.') }}
                </p>
            </div>

            @if ($landing['show_domains'])
                <form @submit.prevent="searchDomains()" class="mt-10 max-w-2xl">
                    <div class="panel flex flex-col sm:flex-row gap-2 p-2 rounded-xl border border-white/10">
                        <input
                            type="text"
                            x-model="query"
                            placeholder="Search any domain…"
                            class="flex-1 bg-transparent px-4 py-3 text-white placeholder:text-slate-500 focus:outline-none"
                            autocomplete="off"
                        >
                        <button type="submit" :disabled="searching || query.trim().length < 2"
                            class="brand-btn font-semibold px-6 py-3 rounded-lg disabled:opacity-60">
                            <span x-text="searching ? 'Searching…' : 'Check availability'"></span>
                        </button>
                    </div>
                    <p x-show="searchError" x-text="searchError" class="mt-3 text-sm text-rose-300"></p>
                </form>
            @endif
        </section>

        @include('public.landing.partials.domain-results', [
            'resultsClass' => 'border-y border-white/10 bg-black/20',
            'resultsContainerClass' => 'max-w-6xl mx-auto px-4 py-8',
            'resultsHeadingClass' => 'serif text-2xl text-white mb-4',
            'resultsCardClass' => 'panel rounded-xl border border-white/10 overflow-hidden',
            'orderBtnClass' => 'brand-btn text-sm font-semibold px-4 py-2 rounded-md disabled:opacity-60',
        ])

        @if ($landing['show_domains'] && count($extensions) > 0)
            <section id="domains-pricing" class="max-w-6xl mx-auto px-4 py-16">
                <h2 class="serif text-3xl text-white">Extension rates</h2>
                <p class="mt-2 text-slate-400">Retail register prices for your catalog.</p>
                <div class="mt-8 overflow-x-auto rounded-xl border border-white/10 panel">
                    <table class="w-full text-sm">
                        <thead class="text-left text-slate-400 border-b border-white/10">
                            <tr>
                                <th class="px-4 py-3 font-medium">TLD</th>
                                <th class="px-4 py-3 font-medium">Register</th>
                                <th class="px-4 py-3 font-medium">Renew</th>
                                <th class="px-4 py-3 font-medium">Transfer</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach ($extensions as $ext)
                                <tr class="hover:bg-white/5">
                                    <td class="px-4 py-3 font-semibold text-white">{{ $ext['extension'] }}</td>
                                    <td class="px-4 py-3 brand-text font-semibold">KES {{ number_format((float) $ext['price'], 2) }}</td>
                                    <td class="px-4 py-3 text-slate-300">
                                        @if ($ext['renewal_price'] !== null)
                                            KES {{ number_format((float) $ext['renewal_price'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-300">
                                        @if ($ext['transfer_price'] !== null)
                                            KES {{ number_format((float) $ext['transfer_price'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($landing['show_hosting'])
            <section id="hosting" class="max-w-6xl mx-auto px-4 pb-20 space-y-12">
                <div>
                    <h2 class="serif text-3xl text-white">Hosting showcase</h2>
                    <p class="mt-2 text-slate-400">Plans from your reseller catalog.</p>
                </div>
                @forelse ($serviceGroups as $group)
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-[0.2em] brand-text mb-4">{{ $group['label'] }}</h3>
                        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
                            @foreach ($group['products'] as $product)
                                <article class="panel rounded-xl border border-white/10 p-6 flex flex-col">
                                    <h4 class="text-xl font-semibold text-white">{{ $product['name'] }}</h4>
                                    @if (! empty($product['description']))
                                        <p class="mt-2 text-sm text-slate-400">{{ \Illuminate\Support\Str::limit($product['description'], 110) }}</p>
                                    @endif
                                    <div class="mt-5">
                                        <span class="text-3xl font-bold text-white">KES {{ number_format((float) ($product['monthly_price'] ?? 0), 0) }}</span>
                                        <span class="text-sm text-slate-500">/mo</span>
                                    </div>
                                    @if (! empty($product['features']) && is_array($product['features']))
                                        <ul class="mt-5 space-y-2 text-sm text-slate-300 flex-1">
                                            @foreach (array_slice($product['features'], 0, 5) as $feature)
                                                <li class="flex gap-2">
                                                    <span class="brand-text">▸</span>
                                                    <span>{{ is_array($feature) ? ($feature['label'] ?? $feature['name'] ?? json_encode($feature)) : $feature }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <div class="flex-1"></div>
                                    @endif
                                    <button type="button" @click="orderHosting({{ (int) $product['id'] }})" :disabled="ordering"
                                        class="mt-6 w-full brand-btn font-semibold py-3 rounded-lg disabled:opacity-60">
                                        Order now
                                    </button>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-slate-400">Add catalog products to showcase hosting here.</p>
                @endforelse
            </section>
        @endif

        <footer class="border-t border-white/10">
            <div class="max-w-6xl mx-auto px-4 py-10 grid sm:grid-cols-3 gap-8 text-sm">
                <div>
                    <p class="font-semibold text-white">{{ $branding['company_name'] }}</p>
                    @if (! empty($branding['footer_text']))
                        <p class="mt-2 text-slate-400">{{ $branding['footer_text'] }}</p>
                    @endif
                </div>
                <div class="text-slate-400 space-y-1">
                    @if (! empty($branding['support_email']))
                        <a href="mailto:{{ $branding['support_email'] }}" class="block hover:text-white">{{ $branding['support_email'] }}</a>
                    @endif
                    @if (! empty($branding['support_phone']))
                        <p>{{ $branding['support_phone'] }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-4 text-slate-400">
                    <a href="{{ $loginUrl }}" class="hover:text-white">Login</a>
                    <a href="{{ $registerUrl }}" class="hover:text-white">Register</a>
                    <a href="{{ route('terms') }}" class="hover:text-white">Terms</a>
                    <a href="{{ route('privacy') }}" class="hover:text-white">Privacy</a>
                </div>
            </div>
            <div class="border-t border-white/5">
                <div class="max-w-6xl mx-auto px-4 py-4 text-xs text-slate-500">
                    &copy; {{ date('Y') }} {{ $branding['company_name'] }}
                </div>
            </div>
        </footer>
    </div>

    @include('public.landing.partials.storefront-script')
</body>
</html>
