<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Checkout — {{ $branding['company_name'] ?? config('app.name', 'Talksasa Cloud') }}</title>
    @if (! empty($branding['favicon_url']))
        <link rel="icon" href="{{ $branding['favicon_url'] }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --cyan-bright: {{ $branding['primary_color'] ?? '#00D9FF' }};
            --cyan-dark: color-mix(in srgb, var(--cyan-bright) 75%, #000);
            --neon-green: #00FF88;
            --dark-bg: #0F172A;
            --dark-card: #1E293B;
            --dark-border: #334155;
            --brand: var(--cyan-bright);
        }

        body {
            background: linear-gradient(135deg, #0F172A 0%, #1a1f3a 50%, #0F172A 100%);
            color: #e2e8f0;
        }

        .glow-cyan {
            box-shadow: 0 0 20px rgba(0, 217, 255, 0.3), inset 0 0 20px rgba(0, 217, 255, 0.1);
        }

        .text-gradient {
            background: linear-gradient(135deg, #ffffff 0%, var(--cyan-bright) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .accent-green {
            color: var(--neon-green);
        }

        .btn-cyan {
            background: linear-gradient(135deg, var(--cyan-bright) 0%, #0099CC 100%);
            color: #0F172A;
            font-weight: 600;
            padding: 0.75rem 2rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            box-shadow: 0 0 20px rgba(0, 217, 255, 0.3);
        }

        .btn-cyan:hover {
            box-shadow: 0 0 30px rgba(0, 217, 255, 0.5);
            transform: translateY(-2px);
        }

        .card-dark {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(0, 217, 255, 0.2);
            border-radius: 1rem;
            backdrop-filter: blur(10px);
        }

        .input-dark {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(0, 217, 255, 0.2);
            color: #e2e8f0;
            padding: 0.875rem 1rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
        }

        .input-dark:focus {
            outline: none;
            border-color: var(--cyan-bright);
            box-shadow: 0 0 15px rgba(0, 217, 255, 0.3);
            background: rgba(30, 41, 59, 0.8);
        }

        .nav-link {
            color: #cbd5e1;
            transition: color 0.3s ease;
            text-decoration: none;
        }

        .nav-link:hover {
            color: var(--cyan-bright);
        }
    </style>
</head>
<body class="bg-[#0F172A]" x-data="checkoutApp(@js($currencyCode), @js($currency?->symbol ?? 'KES'), {{ $currency?->exchange_rate ?? 1 }}, @js(old('checkout_mode', 'register')))">
    <!-- Navigation -->
    <nav class="fixed w-full top-0 z-50 bg-[#0F172A]/90 backdrop-blur-lg border-b border-[rgba(0,217,255,0.1)]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ $cartUrl ?? '/' }}" class="flex items-center gap-3 hover:opacity-75 transition">
                    @if (! empty($branding['logo_url']))
                        <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['company_name'] }}" class="h-10 w-auto max-w-[140px] object-contain">
                    @else
                        <span class="text-xl font-bold text-gradient">{{ $branding['company_name'] ?? config('app.name') }}</span>
                    @endif
                </a>
            </div>

            <div class="flex items-center gap-4 text-sm">
                <a href="{{ $cartUrl ?? '/' }}" class="nav-link">Cart</a>
                @auth
                    <span class="text-slate-300">{{ auth()->user()->name }}</span>
                @else
                    <span class="text-slate-400">Checkout</span>
                @endauth
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <section class="pt-32 pb-20 px-4 sm:px-6 lg:px-8 min-h-screen">
        <div class="max-w-7xl mx-auto">
            <div class="grid md:grid-cols-3 gap-8">
                <!-- Left: Order Summary & Account Form -->
                <div class="md:col-span-2 space-y-8">
                    <!-- Order Summary -->
                    <div class="card-dark p-8">
                        <h2 class="text-2xl font-bold text-white mb-6">Order Summary</h2>

                        <div class="space-y-4">
                            @foreach ($cartItems as $item)
                                <div class="flex justify-between items-start border-b border-[rgba(0,217,255,0.1)] pb-4 last:border-0">
                                    <div class="flex-1">
                                        <p class="font-semibold text-white">{{ $item['name'] }}</p>
                                        <p class="text-sm text-slate-400">{{ $item['description'] ?? '' }}</p>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <p class="text-lg font-bold text-cyan-400">{{ $currency?->symbol ?? 'KES' }} {{ number_format($item['amount'] * ($currency?->exchange_rate ?? 1), 0) }}</p>
                                        @if (! empty($item['key']) && ! empty($isResellerStorefront))
                                            <form method="POST" action="{{ route('reseller.public.store.cart.remove', $item['key']) }}">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="redirect_to" value="checkout">
                                                <button type="submit" class="text-slate-400 hover:text-red-400" title="Remove item">
                                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                                    </svg>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Account: login or register (WHMCS-style) -->
                    @guest
                        <div class="card-dark p-8">
                            @if (! empty($isResellerStorefront) && $loginAtCheckoutUrl)
                                <div class="flex rounded-lg overflow-hidden border border-[rgba(0,217,255,0.2)] mb-6">
                                    <button type="button" @click="mode = 'login'"
                                        :class="mode === 'login' ? 'bg-cyan-500/20 text-cyan-300' : 'text-slate-400 hover:text-white'"
                                        class="flex-1 px-4 py-3 text-sm font-semibold transition">
                                        Existing Customer
                                    </button>
                                    <button type="button" @click="mode = 'register'"
                                        :class="mode === 'register' ? 'bg-cyan-500/20 text-cyan-300' : 'text-slate-400 hover:text-white'"
                                        class="flex-1 px-4 py-3 text-sm font-semibold transition border-l border-[rgba(0,217,255,0.2)]">
                                        New Customer
                                    </button>
                                </div>

                                <div x-show="mode === 'login'" x-cloak>
                                    <h2 class="text-2xl font-bold text-white mb-2">Log in to pay</h2>
                                    <p class="text-slate-400 mb-6">Sign in with your existing client account to complete this order.</p>

                                    <form action="{{ $loginAtCheckoutUrl }}" method="POST" class="space-y-5">
                                        @csrf
                                        <input type="hidden" name="checkout_mode" value="login">
                                        <div>
                                            <label for="login_email" class="block text-sm font-medium text-white mb-2">Email Address</label>
                                            <input type="email" id="login_email" name="email" value="{{ old('email') }}"
                                                class="input-dark w-full @error('email') border-red-500 @enderror" required autocomplete="username">
                                            @error('email')
                                                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div>
                                            <label for="login_password" class="block text-sm font-medium text-white mb-2">Password</label>
                                            <input type="password" id="login_password" name="password"
                                                class="input-dark w-full @error('password') border-red-500 @enderror" required autocomplete="current-password">
                                            @error('password')
                                                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <label class="flex items-center gap-2 text-sm text-slate-300">
                                            <input type="checkbox" name="remember" class="rounded">
                                            Remember me
                                        </label>
                                        <button type="submit" class="btn-cyan w-full">Log in &amp; Continue</button>
                                    </form>
                                </div>
                            @endif

                            <div @if (! empty($isResellerStorefront) && $loginAtCheckoutUrl) x-show="mode === 'register'" x-cloak @endif>
                                <h2 class="text-2xl font-bold text-white mb-2">Create Your Account</h2>
                                <p class="text-slate-400 mb-6">Register to manage your domains and services, then pay this order.</p>

                                <form action="{{ route('customer.checkout.process') }}" method="POST" class="space-y-6">
                                    @csrf

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label for="checkout-first-name" class="block text-sm font-medium text-white mb-2">First name</label>
                                            <input type="text" id="checkout-first-name" name="first_name" value="{{ old('first_name') }}"
                                                class="input-dark w-full @error('first_name') border-red-500 @enderror" required autocomplete="given-name">
                                            @error('first_name')
                                                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div>
                                            <label for="checkout-last-name" class="block text-sm font-medium text-white mb-2">Last name <span class="text-slate-400">(optional)</span></label>
                                            <input type="text" id="checkout-last-name" name="last_name" value="{{ old('last_name') }}"
                                                class="input-dark w-full @error('last_name') border-red-500 @enderror" autocomplete="family-name">
                                            @error('last_name')
                                                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                    <x-country-select
                                        name="country"
                                        label="Country"
                                        :value="old('country')"
                                        :required="true"
                                        variant="public"
                                        placeholder="Select your country"
                                    />

                                    <div>
                                        <label for="email" class="block text-sm font-medium text-white mb-2">Email Address</label>
                                        <input type="email" id="email" name="email" value="{{ old('email') }}"
                                            class="input-dark w-full @error('email') border-red-500 @enderror" required>
                                        @error('email')
                                            <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div>
                                        <label for="password" class="block text-sm font-medium text-white mb-2">Password</label>
                                        <input type="password" id="password" name="password"
                                            class="input-dark w-full @error('password') border-red-500 @enderror" required>
                                        <p class="mt-1 text-sm text-slate-500">At least 8 characters</p>
                                        @error('password')
                                            <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div>
                                        <label for="password_confirmation" class="block text-sm font-medium text-white mb-2">Confirm Password</label>
                                        <input type="password" id="password_confirmation" name="password_confirmation" class="input-dark w-full" required>
                                    </div>

                                    <div class="bg-[rgba(0,217,255,0.1)] border border-[rgba(0,217,255,0.2)] rounded-lg p-4">
                                        <label class="flex items-start gap-3 cursor-pointer">
                                            <input type="checkbox" name="agree_terms" class="mt-1 @error('agree_terms') border-red-500 @enderror" required>
                                            <span class="text-sm text-slate-300">
                                                I agree to the <a href="{{ route('terms') }}" class="text-cyan-400 hover:underline">Terms of Service</a> and <a href="{{ route('privacy') }}" class="text-cyan-400 hover:underline">Privacy Policy</a>. I authorize the charge for this order.
                                            </span>
                                        </label>
                                        @error('agree_terms')
                                            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <button type="submit" class="btn-cyan w-full">
                                        Create Account &amp; Complete Order
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endguest

                    @auth
                        <!-- Authenticated user checkout -->
                        <div class="card-dark p-8">
                            <h2 class="text-2xl font-bold text-white mb-2">Complete Your Order</h2>
                            <p class="text-slate-400 mb-6">Signed in as {{ auth()->user()->email }}</p>

                            <form action="{{ route('customer.checkout.process') }}" method="POST" class="space-y-6">
                                @csrf

                                <div class="bg-[rgba(0,217,255,0.1)] border border-[rgba(0,217,255,0.2)] rounded-lg p-4 mb-6">
                                    <label class="flex items-start gap-3 cursor-pointer">
                                        <input type="checkbox" name="agree_terms" class="mt-1" required>
                                        <span class="text-sm text-slate-300">
                                            I agree to the <a href="{{ route('terms') }}" class="text-cyan-400 hover:underline">Terms of Service</a> and <a href="{{ route('privacy') }}" class="text-cyan-400 hover:underline">Privacy Policy</a>, and authorize the charge for this order.
                                        </span>
                                    </label>
                                </div>

                                <button type="submit" class="btn-cyan w-full">
                                    Complete Order &amp; Pay
                                </button>
                            </form>
                        </div>
                    @endauth
                </div>

                <!-- Right: Order Total -->
                <div>
                    <div class="card-dark p-8 sticky top-32">
                        <h3 class="text-xl font-bold text-white mb-6">Order Total</h3>

                        <div class="space-y-4 mb-6 pb-6 border-b border-[rgba(0,217,255,0.1)]">
                            <div class="flex justify-between text-slate-300">
                                <span>Subtotal</span>
                                <span class="font-semibold">{{ $currency?->symbol ?? 'KES' }} {{ number_format($subtotal * ($currency?->exchange_rate ?? 1), 0) }}</span>
                            </div>
                            @if (($discount ?? 0) > 0)
                                <div class="flex justify-between text-emerald-400">
                                    <span>Promo{{ ! empty($promoCode) ? ' ('.$promoCode.')' : '' }}</span>
                                    <span class="font-semibold">−{{ $currency?->symbol ?? 'KES' }} {{ number_format($discount * ($currency?->exchange_rate ?? 1), 0) }}</span>
                                </div>
                            @endif
                            @if ($taxEnabled && $tax > 0)
                                <div class="flex justify-between text-slate-300">
                                    <span>Tax ({{ $taxRate }}%)</span>
                                    <span class="font-semibold">{{ $currency?->symbol ?? 'KES' }} {{ number_format($tax * ($currency?->exchange_rate ?? 1), 0) }}</span>
                                </div>
                            @endif
                        </div>

                        <div class="flex justify-between items-center mb-8">
                            <span class="text-lg font-bold text-white">Total</span>
                            <span class="text-3xl font-bold text-gradient">{{ $currency?->symbol ?? 'KES' }} {{ number_format($total * ($currency?->exchange_rate ?? 1), 0) }}</span>
                        </div>

                        <div class="pt-6 border-t border-[rgba(0,217,255,0.1)]">
                            <div class="space-y-2 text-sm text-slate-400">
                                <p>✓ Secure checkout</p>
                                <p>✓ SSL encrypted payments</p>
                                <p>✓ M-Pesa, cards &amp; PayPal</p>
                                <p>✓ Invoice generated after order</p>
                            </div>
                        </div>

                        <a href="{{ $cartUrl ?? '/' }}" class="block mt-6 text-center text-cyan-400 hover:text-cyan-300 font-semibold">
                            ← Back to cart
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer class="border-t border-[rgba(0,217,255,0.1)] py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-7xl mx-auto">
            <div class="flex flex-wrap justify-between items-center gap-4 text-sm text-slate-400">
                <p>&copy; {{ date('Y') }} {{ $branding['company_name'] ?? config('app.name') }}. All rights reserved.</p>
                <div class="flex gap-6">
                    <a href="{{ route('privacy') }}" class="nav-link">Privacy</a>
                    <a href="{{ route('terms') }}" class="nav-link">Terms</a>
                    @if (! empty($branding['support_email']))
                        <a href="mailto:{{ $branding['support_email'] }}" class="nav-link">Support</a>
                    @endif
                </div>
            </div>
        </div>
    </footer>

    <script>
        function checkoutApp(currencyCode, currencySymbol, exchangeRate, initialMode) {
            return {
                currencyCode: currencyCode,
                currencySymbol: currencySymbol,
                exchangeRate: exchangeRate,
                mode: initialMode === 'login' ? 'login' : 'register',
            };
        }
    </script>
    <x-app-dialog />
</body>
</html>
