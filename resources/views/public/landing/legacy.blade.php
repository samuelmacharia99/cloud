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
    <style>
        :root {
            --brand: {{ $branding['primary_color'] ?? '#1a4b8c' }};
            --brand-dark: color-mix(in srgb, var(--brand) 82%, #000);
        }
        .brand-bg { background-color: var(--brand); }
        .brand-bg-dark { background-color: var(--brand-dark); }
        .brand-text { color: var(--brand); }
        .brand-border { border-color: var(--brand); }
        .brand-btn {
            background-color: var(--brand);
        }
        .brand-btn:hover {
            background-color: var(--brand-dark);
        }
        .legacy-hero {
            background:
                linear-gradient(135deg, color-mix(in srgb, var(--brand) 92%, #0b1c33) 0%, var(--brand) 48%, color-mix(in srgb, var(--brand) 70%, #0ea5e9) 100%);
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 antialiased" x-data="storefrontPanel()">
    {{-- Top utility bar --}}
    <div class="brand-bg-dark text-white text-xs sm:text-sm">
        <div class="max-w-6xl mx-auto px-4 py-2 flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-4 opacity-95">
                @if (! empty($branding['support_phone']))
                    <span>Phone: {{ $branding['support_phone'] }}</span>
                @endif
                @if (! empty($branding['support_email']))
                    <a href="mailto:{{ $branding['support_email'] }}" class="hover:underline">{{ $branding['support_email'] }}</a>
                @endif
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ $loginUrl }}" class="hover:underline">Client Login</a>
                <span class="opacity-40">|</span>
                <a href="{{ $cartPageUrl ?? route('reseller.public.store.cart.show') }}" class="hover:underline">
                    Cart{{ ($cartCount ?? 0) > 0 ? " (".$cartCount.")" : "" }}
                </a>
                <span class="opacity-40">|</span>
                <a href="{{ $registerUrl }}" class="hover:underline">Register</a>
            </div>
        </div>
    </div>

    {{-- Main nav --}}
    <header class="bg-white border-b border-slate-200 shadow-sm sticky top-0 z-40">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="flex items-center gap-3 min-w-0">
                @if (! empty($branding['logo_url']))
                    <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['company_name'] }}" class="h-10 w-auto max-w-[180px] object-contain">
                @else
                    <span class="text-lg font-bold brand-text truncate">{{ $branding['company_name'] }}</span>
                @endif
            </a>
            <nav class="hidden md:flex items-center gap-6 text-sm font-semibold text-slate-700">
                <a href="#home" class="hover:opacity-80 brand-text">Home</a>
                @if ($landing['show_domains'])
                    <a href="#domains" class="hover:text-slate-900">Domains</a>
                @endif
                @if ($landing['show_hosting'])
                    <a href="#hosting" class="hover:text-slate-900">Hosting</a>
                @endif
                <a href="{{ $loginUrl }}" class="brand-btn text-white px-4 py-2 rounded-md shadow-sm">Client Area</a>
                <a href="{{ $cartPageUrl ?? route('reseller.public.store.cart.show') }}" class="text-slate-700 hover:text-slate-900">
                    Cart{{ ($cartCount ?? 0) > 0 ? " (".$cartCount.")" : "" }}
                </a>
            </nav>
            <a href="{{ $loginUrl }}" class="md:hidden brand-btn text-white px-3 py-2 rounded-md text-sm font-semibold">Login</a>
        </div>
    </header>

    {{-- Hero / domain search --}}
    <section id="home" class="legacy-hero text-white">
        <div class="max-w-6xl mx-auto px-4 py-14 sm:py-20 text-center">
            <h1 class="text-3xl sm:text-4xl font-bold tracking-tight">
                {{ $landing['hero_headline'] ?: ($branding['company_name'] ?? 'Web Hosting') }}
            </h1>
            <p class="mt-3 text-white/90 max-w-2xl mx-auto text-base sm:text-lg">
                {{ $landing['hero_subtext'] ?: ($branding['tagline'] ?: 'Find your perfect domain name and choose a hosting plan that fits.') }}
            </p>

            @if ($landing['show_domains'])
                <form @submit.prevent="searchDomains()" class="mt-8 max-w-3xl mx-auto">
                    <div class="bg-white rounded-lg shadow-xl p-2 sm:p-3 flex flex-col sm:flex-row gap-2">
                        <input
                            type="text"
                            x-model="query"
                            placeholder="Find your new domain name..."
                            class="flex-1 px-4 py-3 rounded-md text-slate-900 border border-slate-200 focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                            autocomplete="off"
                        >
                        <button
                            type="submit"
                            :disabled="searching || query.trim().length < 2"
                            class="brand-btn text-white font-semibold px-6 py-3 rounded-md disabled:opacity-60"
                        >
                            <span x-text="searching ? 'Searching…' : 'Search'"></span>
                        </button>
                    </div>
                    <p x-show="searchError" x-text="searchError" class="mt-3 text-sm text-amber-100"></p>
                </form>
            @endif
        </div>
    </section>

    @if ($landing['show_domains'] && count($extensions) > 0)
        <section class="bg-white border-b border-slate-200">
            <div class="max-w-6xl mx-auto px-4 py-6">
                <div class="flex flex-wrap justify-center gap-3 sm:gap-5">
                    @foreach (array_slice($extensions, 0, 12) as $ext)
                        <div class="text-center min-w-[5.5rem] px-3 py-2 rounded-md bg-slate-50 border border-slate-200">
                            <div class="font-bold text-slate-800">{{ $ext['extension'] }}</div>
                            <div class="text-sm brand-text font-semibold mt-0.5">
                                KES {{ number_format((float) $ext['price'], 0) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Search results --}}
    @include('public.landing.partials.domain-results')

    @if ($landing['show_domains'] && count($extensions) > 0)
        <section id="domains-pricing" class="py-12 bg-white">
            <div class="max-w-6xl mx-auto px-4">
                <div class="text-center mb-8">
                    <h2 class="text-2xl font-bold text-slate-900">Domain pricing</h2>
                    <p class="text-slate-600 mt-2">Transparent retail prices for popular extensions.</p>
                </div>
                <div class="overflow-x-auto rounded-lg border border-slate-200 shadow-sm">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left">
                            <tr>
                                <th class="px-4 py-3 font-semibold text-slate-700">Extension</th>
                                <th class="px-4 py-3 font-semibold text-slate-700">Register</th>
                                <th class="px-4 py-3 font-semibold text-slate-700">Renew</th>
                                <th class="px-4 py-3 font-semibold text-slate-700">Transfer</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach ($extensions as $ext)
                                <tr>
                                    <td class="px-4 py-3 font-semibold">{{ $ext['extension'] }}</td>
                                    <td class="px-4 py-3">KES {{ number_format((float) $ext['price'], 2) }}</td>
                                    <td class="px-4 py-3">
                                        @if ($ext['renewal_price'] !== null)
                                            KES {{ number_format((float) $ext['renewal_price'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
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
            </div>
        </section>
    @endif

    @if ($landing['show_hosting'])
        <section id="hosting" class="py-12 bg-slate-100">
            <div class="max-w-6xl mx-auto px-4 space-y-12">
                <div class="text-center">
                    <h2 class="text-2xl font-bold text-slate-900">Hosting plans</h2>
                    <p class="text-slate-600 mt-2">Choose a plan and check out in a few clicks.</p>
                </div>

                @forelse ($serviceGroups as $group)
                    <div>
                        <h3 class="text-lg font-bold text-slate-800 mb-4 border-l-4 brand-border pl-3">{{ $group['label'] }}</h3>
                        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
                            @foreach ($group['products'] as $product)
                                <div class="bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                                    <div class="brand-bg text-white px-5 py-4">
                                        <h4 class="font-bold text-lg">{{ $product['name'] }}</h4>
                                        @if (! empty($product['category']))
                                            <p class="text-xs text-white/80 mt-1">{{ $product['category'] }}</p>
                                        @endif
                                    </div>
                                    <div class="p-5 flex-1 flex flex-col">
                                        <div class="mb-4">
                                            <div class="text-3xl font-bold text-slate-900">
                                                KES {{ number_format((float) ($product['monthly_price'] ?? 0), 0) }}
                                                <span class="text-sm font-normal text-slate-500">/mo</span>
                                            </div>
                                            @if (! empty($product['yearly_price']))
                                                <p class="text-xs text-slate-500 mt-1">
                                                    or KES {{ number_format((float) $product['yearly_price'], 0) }}/yr
                                                </p>
                                            @endif
                                            @if (! empty($product['setup_fee']) && (float) $product['setup_fee'] > 0)
                                                <p class="text-xs text-slate-500 mt-1">
                                                    Setup: KES {{ number_format((float) $product['setup_fee'], 0) }}
                                                </p>
                                            @endif
                                        </div>
                                        @if (! empty($product['description']))
                                            <p class="text-sm text-slate-600 mb-4">{{ \Illuminate\Support\Str::limit($product['description'], 140) }}</p>
                                        @endif
                                        @if (! empty($product['features']) && is_array($product['features']))
                                            <ul class="space-y-1.5 text-sm text-slate-700 mb-5 flex-1">
                                                @foreach (array_slice($product['features'], 0, 6) as $feature)
                                                    <li class="flex gap-2">
                                                        <span class="brand-text font-bold">✓</span>
                                                        <span>{{ is_array($feature) ? ($feature['label'] ?? $feature['name'] ?? json_encode($feature)) : $feature }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <div class="flex-1"></div>
                                        @endif
                                        <a
                                            href="{{ $product['order_path'] ?? '#' }}"
                                            @click.prevent="orderHosting({{ (int) $product['id'] }})"
                                            :class="{ 'pointer-events-none opacity-60': ordering }"
                                            class="w-full brand-btn text-white font-semibold py-2.5 rounded-md text-center block"
                                        >
                                            Order now
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="bg-white border border-dashed border-slate-300 rounded-lg p-8 text-center text-slate-600">
                        Hosting plans will appear here once they are added to the reseller catalog.
                    </div>
                @endforelse
            </div>
        </section>
    @endif

    <footer class="brand-bg-dark text-white">
        <div class="max-w-6xl mx-auto px-4 py-10 grid sm:grid-cols-3 gap-8 text-sm">
            <div>
                <p class="font-bold text-base">{{ $branding['company_name'] }}</p>
                @if (! empty($branding['tagline']))
                    <p class="mt-2 text-white/75">{{ $branding['tagline'] }}</p>
                @endif
                @if (! empty($branding['footer_text']))
                    <p class="mt-3 text-white/70">{{ $branding['footer_text'] }}</p>
                @endif
            </div>
            <div>
                <p class="font-semibold mb-2">Support</p>
                @if (! empty($branding['support_email']))
                    <p><a class="text-white/80 hover:text-white" href="mailto:{{ $branding['support_email'] }}">{{ $branding['support_email'] }}</a></p>
                @endif
                @if (! empty($branding['support_phone']))
                    <p class="mt-1 text-white/80">{{ $branding['support_phone'] }}</p>
                @endif
            </div>
            <div>
                <p class="font-semibold mb-2">Client area</p>
                <a href="{{ $loginUrl }}" class="block text-white/80 hover:text-white">Login</a>
                <a href="{{ $registerUrl }}" class="block text-white/80 hover:text-white mt-1">Create account</a>
            </div>
        </div>
        <div class="border-t border-white/10">
            <div class="max-w-6xl mx-auto px-4 py-4 text-xs text-white/60 flex flex-wrap justify-between gap-2">
                <span>&copy; {{ date('Y') }} {{ $branding['company_name'] }}. All rights reserved.</span>
                <span class="flex gap-3">
                    <a href="{{ route('terms') }}" class="hover:text-white">Terms</a>
                    <a href="{{ route('privacy') }}" class="hover:text-white">Privacy</a>
                </span>
            </div>
        </div>
    </footer>

    @include('public.landing.partials.storefront-script')
</body>
</html>
