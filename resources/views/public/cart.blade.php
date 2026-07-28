<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Review & Checkout — {{ $branding['company_name'] ?? config('app.name') }}</title>
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
        .brand-text { color: var(--brand); }
        .brand-btn { background-color: var(--brand); }
        .brand-btn:hover { background-color: var(--brand-dark); }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 antialiased min-h-screen">
    <header class="bg-white border-b border-slate-200">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between gap-4">
            <a href="{{ $continueUrl }}" class="flex items-center gap-3 min-w-0">
                @if (! empty($branding['logo_url']))
                    <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['company_name'] }}" class="h-9 w-auto max-w-[160px] object-contain">
                @else
                    <span class="font-semibold brand-text truncate">{{ $branding['company_name'] }}</span>
                @endif
            </a>
            <div class="flex items-center gap-4 text-sm">
                <a href="{{ $continueUrl }}" class="text-slate-600 hover:text-slate-900">Continue shopping</a>
                @auth
                    <span class="text-slate-500">{{ auth()->user()->name }}</span>
                @else
                    <a href="{{ $loginUrl }}" class="text-slate-600 hover:text-slate-900">Login</a>
                @endauth
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 py-10">
        <div class="mb-8">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">Shopping Cart</h1>
            <p class="text-slate-600 mt-1">Review your order, then create an account or log in to pay.</p>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 text-sm">{{ session('error') }}</div>
        @endif

        @if ($itemCount === 0)
            <div class="bg-white rounded-xl border border-slate-200 p-10 text-center">
                <p class="text-slate-700 font-medium">Your cart is empty.</p>
                <p class="text-sm text-slate-500 mt-2">Add a domain or hosting plan from the storefront to get started.</p>
                <a href="{{ $continueUrl }}" class="inline-flex mt-6 brand-btn text-white font-semibold px-5 py-2.5 rounded-lg">Continue shopping</a>
            </div>
        @else
            <div class="grid lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-4">
                    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Product / Service</th>
                                    <th class="px-4 py-3">Billing</th>
                                    <th class="px-4 py-3 text-right">Price</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($cartItems as $item)
                                    <tr>
                                        <td class="px-4 py-4">
                                            <p class="font-semibold text-slate-900">{{ $item['name'] }}</p>
                                            <p class="text-slate-500 mt-0.5">{{ $item['description'] ?? '' }}</p>
                                        </td>
                                        <td class="px-4 py-4 text-slate-600">
                                            @if (! empty($item['billing_cycle']))
                                                {{ ucfirst(str_replace('-', ' ', $item['billing_cycle'])) }}
                                            @else
                                                {{ $item['years'] ?? 1 }} Year(s)
                                            @endif
                                        </td>
                                        <td class="px-4 py-4 text-right font-semibold text-slate-900">
                                            {{ $currency?->symbol ?? 'KES' }} {{ number_format($item['amount'] * ($currency?->exchange_rate ?? 1), 0) }}
                                        </td>
                                        <td class="px-4 py-4 text-right">
                                            <form method="POST" action="{{ route('reseller.public.store.cart.remove', $item['key']) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-rose-600 hover:text-rose-700 text-xs font-semibold">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <a href="{{ $continueUrl }}" class="px-4 py-2 rounded-lg border border-slate-300 text-sm font-medium text-slate-700 hover:bg-white">← Continue shopping</a>
                        <form method="POST" action="{{ route('reseller.public.store.cart.clear') }}">
                            @csrf
                            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-500 hover:text-rose-600">Empty cart</button>
                        </form>
                    </div>
                </div>

                <aside class="bg-white rounded-xl border border-slate-200 p-6 h-fit sticky top-6">
                    <h2 class="font-bold text-slate-900 text-lg">Order Summary</h2>
                    <div class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between text-slate-600">
                            <span>Subtotal</span>
                            <span>{{ $currency?->symbol ?? 'KES' }} {{ number_format($subtotal * ($currency?->exchange_rate ?? 1), 0) }}</span>
                        </div>
                        @if ($taxEnabled && $tax > 0)
                            <div class="flex justify-between text-slate-600">
                                <span>Tax ({{ $taxRate }}%)</span>
                                <span>{{ $currency?->symbol ?? 'KES' }} {{ number_format($tax * ($currency?->exchange_rate ?? 1), 0) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between text-base font-bold text-slate-900 pt-3 border-t border-slate-100">
                            <span>Total</span>
                            <span class="brand-text">{{ $currency?->symbol ?? 'KES' }} {{ number_format($total * ($currency?->exchange_rate ?? 1), 0) }}</span>
                        </div>
                    </div>

                    <a href="{{ $checkoutUrl }}" class="mt-6 block w-full text-center brand-btn text-white font-semibold py-3 rounded-lg">
                        Checkout
                    </a>
                    <p class="mt-3 text-xs text-slate-500 text-center">
                        @guest
                            Create an account or log in on the next step to pay.
                        @else
                            You are signed in — continue to complete payment.
                        @endguest
                    </p>
                </aside>
            </div>
        @endif
    </main>
</body>
</html>
