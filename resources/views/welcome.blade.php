<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Talksasa Cloud') }} — Cloud Hosting Platform</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        :root {
            --cyan-bright: #00D9FF;
            --cyan-dark: #0099CC;
            --neon-green: #00FF88;
            --dark-bg: #0F172A;
            --dark-card: #1E293B;
            --dark-border: #334155;
        }

        * {
            scroll-behavior: smooth;
        }

        body {
            background: linear-gradient(135deg, #0F172A 0%, #1a1f3a 50%, #0F172A 100%);
            color: #e2e8f0;
            overflow-x: hidden;
        }

        /* Glow effects */
        .glow-cyan {
            box-shadow: 0 0 20px rgba(0, 217, 255, 0.3), inset 0 0 20px rgba(0, 217, 255, 0.1);
        }

        .glow-cyan-sm {
            box-shadow: 0 0 10px rgba(0, 217, 255, 0.2);
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

        .btn-outline {
            border: 2px solid var(--cyan-bright);
            color: var(--cyan-bright);
            background: transparent;
            font-weight: 600;
            padding: 0.75rem 2rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-outline:hover {
            background: rgba(0, 217, 255, 0.1);
            box-shadow: 0 0 20px rgba(0, 217, 255, 0.3);
        }

        .card-dark {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(0, 217, 255, 0.2);
            border-radius: 1rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .card-dark:hover {
            border-color: rgba(0, 217, 255, 0.5);
            box-shadow: 0 0 20px rgba(0, 217, 255, 0.2);
            transform: translateY(-4px);
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

        .badge-cyan {
            background: rgba(0, 217, 255, 0.1);
            border: 1px solid rgba(0, 217, 255, 0.3);
            color: var(--cyan-bright);
            padding: 0.5rem 1rem;
            border-radius: 999px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .feature-icon {
            width: 3rem;
            height: 3rem;
            background: linear-gradient(135deg, rgba(0, 217, 255, 0.2) 0%, rgba(0, 255, 136, 0.2) 100%);
            border: 1px solid rgba(0, 217, 255, 0.3);
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .pricing-highlight {
            border: 2px solid var(--cyan-bright);
            box-shadow: 0 0 30px rgba(0, 217, 255, 0.3);
        }

        .domain-ext-chip {
            background: rgba(0, 217, 255, 0.1);
            border: 1px solid rgba(0, 217, 255, 0.3);
            color: var(--cyan-bright);
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .domain-ext-chip:hover {
            border-color: var(--cyan-bright);
            box-shadow: 0 0 10px rgba(0, 217, 255, 0.3);
        }

        .available-badge {
            background: rgba(0, 255, 136, 0.1);
            color: var(--neon-green);
            border: 1px solid rgba(0, 255, 136, 0.3);
            padding: 0.25rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .unavailable-badge {
            background: rgba(100, 116, 139, 0.1);
            color: #94a3b8;
            border: 1px solid rgba(100, 116, 139, 0.3);
            padding: 0.25rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .loading-spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid rgba(0, 217, 255, 0.3);
            border-top-color: var(--cyan-bright);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .toast {
            position: fixed;
            bottom: 100px;
            right: 1rem;
            padding: 1rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            z-index: 50;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .toast-success {
            background: rgba(0, 255, 136, 0.2);
            border: 1px solid rgba(0, 255, 136, 0.5);
            color: var(--neon-green);
        }

        .toast-error {
            background: rgba(255, 100, 100, 0.2);
            border: 1px solid rgba(255, 100, 100, 0.5);
            color: #ff6464;
        }

        .hero-glow {
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(0, 217, 255, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            filter: blur(40px);
            pointer-events: none;
        }

        .nav-link {
            color: #cbd5e1;
            transition: color 0.3s ease;
            text-decoration: none;
            font-weight: 500;
        }

        .nav-link:hover {
            color: var(--cyan-bright);
        }

        .sticky-checkout-bar {
            background: rgba(15, 23, 42, 0.95);
            border-top: 1px solid rgba(0, 217, 255, 0.2);
            backdrop-filter: blur(10px);
            padding: 1rem;
        }
    </style>
</head>
<body x-data="domainSearchApp()" class="bg-[#0F172A]">
    <!-- Navigation -->
    <nav class="fixed w-full top-0 z-50 bg-[#0F172A]/90 backdrop-blur-lg border-b border-[rgba(0,217,255,0.1)]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-cyan-400 to-cyan-600 flex items-center justify-center glow-cyan-sm">
                    <span class="text-[#0F172A] font-bold text-sm">TC</span>
                </div>
                <span class="text-xl font-bold text-gradient">Talksasa</span>
            </div>

            <div class="hidden md:flex items-center gap-8">
                <a href="#features" class="nav-link">Features</a>
                <a href="#pricing" class="nav-link">Pricing</a>
                <a href="#domain-search" class="nav-link">Domains</a>
            </div>

            <div class="flex items-center gap-4">
                <button @click="goToCheckout()" x-show="cartCount > 0" class="relative nav-link hover:text-cyan-400 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.8 5M7 13l-2 8m10 0l2-8m0 0h-2.5m2.5 0h2.5"/>
                    </svg>
                    <span class="absolute -top-2 -right-2 bg-cyan-500 text-dark-bg text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center" x-text="cartCount"></span>
                </button>
                <a href="{{ route('login') }}" class="hidden sm:inline nav-link">Login</a>
                <a href="{{ route('register') }}" class="btn-cyan text-xs sm:text-sm">Get Started</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="pt-40 pb-20 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
        <div class="hero-glow" style="top: -200px; left: -100px;"></div>
        <div class="hero-glow" style="bottom: 0; right: -100px;"></div>

        <div class="max-w-7xl mx-auto relative z-10">
            <div class="grid md:grid-cols-2 gap-12 items-center">
                <!-- Left Content -->
                <div>
                    <div class="inline-block mb-6">
                        <span class="badge-cyan">🚀 Enterprise Cloud Hosting</span>
                    </div>
                    <h1 class="text-5xl md:text-7xl font-bold mb-6 leading-tight">
                        <span class="text-white">Managed Business</span><br>
                        <span class="accent-green">Hosting</span>
                    </h1>
                    <p class="text-lg text-slate-300 mb-8 leading-relaxed max-w-lg">
                        Provide your website with the exceptional hosting it merits.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-4">
                        <button @click="document.getElementById('domain-search').scrollIntoView()" class="btn-cyan">
                            Explore
                        </button>
                        <a href="{{ route('register') }}" class="btn-outline text-center">
                            Deploy A Copy
                        </a>
                    </div>
                    <p class="text-sm text-slate-400 mt-8">Worry-free: 45 Days Money Back</p>
                </div>

                <!-- Right Visual -->
                <div class="hidden md:block relative">
                    <div class="relative w-full h-96 glow-cyan rounded-2xl border border-[rgba(0,217,255,0.2)] p-8" style="background: rgba(15, 23, 42, 0.6);">
                        <div class="absolute inset-0 rounded-2xl overflow-hidden">
                            <div class="absolute top-10 right-10 w-32 h-32 bg-cyan-500/20 rounded-full blur-3xl"></div>
                            <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-cyan-500/10 rounded-full blur-3xl"></div>
                        </div>
                        <div class="relative space-y-4 text-center h-full flex flex-col justify-center">
                            <div class="text-4xl font-bold text-gradient">⚡</div>
                            <p class="text-sm text-slate-300">Deploy in seconds</p>
                            <p class="text-xs text-slate-400 mt-4">Manage domains, containers, and services</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Domain Search Section -->
    <section id="domain-search" class="py-20 px-4 sm:px-6 lg:px-8">
        <div class="max-w-4xl mx-auto">
            <div class="text-center mb-12">
                <span class="badge-cyan mb-4 inline-block">Find Your Domain</span>
                <h2 class="text-4xl md:text-5xl font-bold mb-4">
                    <span class="text-white">Search </span><span class="accent-green">available</span><span class="text-white"> domains</span>
                </h2>
                <p class="text-slate-400 text-lg">Type a full domain with extension (e.g. google.com)</p>
            </div>

            <!-- Search Form -->
            <div class="card-dark p-6 md:p-8 mb-12">
                <div class="flex gap-3 mb-6">
                    <input
                        type="text"
                        x-model="searchQuery"
                        @keydown.enter="searchDomain()"
                        placeholder="Search for a domain..."
                        class="input-dark flex-1 text-base"
                    >
                    <button
                        @click="searchDomain()"
                        :disabled="loading"
                        class="btn-cyan disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                    >
                        <span x-show="!loading">Search</span>
                        <span x-show="loading" class="loading-spinner"></span>
                    </button>
                </div>

                <!-- Results -->
                <template x-if="showResults && availableDomains.length > 0">
                    <div class="space-y-4">
                        <h3 class="text-lg font-bold text-white flex items-center gap-2 mb-4">
                            <span class="w-6 h-6 rounded-full bg-green-500/20 border border-green-500/50 flex items-center justify-center text-sm font-bold text-neon-green">✓</span>
                            Available (<span x-text="availableDomains.length"></span>)
                        </h3>
                        <template x-for="domain in availableDomains" :key="domain.full_domain">
                            <div class="card-dark p-4 border border-[rgba(0,255,136,0.2)] hover:border-[rgba(0,255,136,0.4)]">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-2">
                                            <h4 class="text-lg font-bold text-white font-mono">
                                                <span x-text="domain.full_domain"></span>
                                            </h4>
                                            <span class="available-badge">Available</span>
                                        </div>
                                        <p class="text-sm text-slate-400">1 year registration</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-2xl font-bold text-gradient">
                                            KES <span x-text="formatPrice(domain.price)"></span>
                                        </p>
                                        <p class="text-xs text-slate-500">per year</p>
                                    </div>
                                </div>
                                <div class="mt-4 flex gap-3">
                                    <template x-if="!isInCart(domain.full_domain)">
                                        <button
                                            type="button"
                                            @click="addToCart(domain)"
                                            class="btn-cyan flex-1 text-sm"
                                        >
                                            Add to Cart
                                        </button>
                                        <button
                                            type="button"
                                            @click="addToCart(domain)"
                                            class="btn-outline flex-1 text-sm"
                                        >
                                            Proceed Shopping
                                        </button>
                                    </template>
                                    <template x-if="isInCart(domain.full_domain)">
                                        <button
                                            type="button"
                                            @click="removeFromCart(domain.full_domain)"
                                            class="flex-1 px-4 py-2 bg-red-500/20 border border-red-500/50 text-red-400 rounded-lg font-semibold hover:bg-red-500/30 transition text-sm"
                                        >
                                            Remove from Cart
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="showResults && unavailableDomains.length > 0">
                    <div class="space-y-4">
                        <h3 class="text-lg font-bold text-slate-400 flex items-center gap-2 mb-4">
                            <span class="w-6 h-6 rounded-full bg-slate-500/20 border border-slate-500/50 flex items-center justify-center text-sm font-bold">✗</span>
                            Unavailable (<span x-text="unavailableDomains.length"></span>)
                        </h3>
                        <template x-for="domain in unavailableDomains" :key="domain.full_domain">
                            <div class="card-dark p-4 opacity-60">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-2">
                                            <h4 class="text-lg font-bold text-slate-300 font-mono">
                                                <span x-text="domain.full_domain"></span>
                                            </h4>
                                            <span class="unavailable-badge">Taken</span>
                                        </div>
                                        <p class="text-sm text-slate-500">This domain is registered</p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="!showResults">
                    <div class="text-center py-12">
                        <p class="text-slate-400 text-lg">Start searching to find available domains</p>
                    </div>
                </template>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="py-20 px-4 sm:px-6 lg:px-8">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-16">
                <span class="badge-cyan mb-4 inline-block">Why Choose Us</span>
                <h2 class="text-4xl md:text-5xl font-bold mb-4">
                    <span class="accent-green">One-click</span> <span class="text-white">apps installation</span>
                </h2>
                <p class="text-slate-400 text-lg">Everything you need to succeed online</p>
            </div>

            <div class="grid md:grid-cols-3 gap-6">
                <div class="card-dark p-8">
                    <div class="feature-icon mb-4">📦</div>
                    <h3 class="text-xl font-bold text-white mb-3">Instant Deploy</h3>
                    <p class="text-slate-400">Deploy containers in seconds with pre-configured templates</p>
                </div>
                <div class="card-dark p-8">
                    <div class="feature-icon mb-4">🌐</div>
                    <h3 class="text-xl font-bold text-white mb-3">Domain Management</h3>
                    <p class="text-slate-400">Search, register, and manage your domains seamlessly</p>
                </div>
                <div class="card-dark p-8">
                    <div class="feature-icon mb-4">🔒</div>
                    <h3 class="text-xl font-bold text-white mb-3">SSL Certificates</h3>
                    <p class="text-slate-400">Automatic SSL provisioning with Let's Encrypt</p>
                </div>
                <div class="card-dark p-8">
                    <div class="feature-icon mb-4">📊</div>
                    <h3 class="text-xl font-bold text-white mb-3">Real-time Metrics</h3>
                    <p class="text-slate-400">Monitor CPU, memory, and network usage in real-time</p>
                </div>
                <div class="card-dark p-8">
                    <div class="feature-icon mb-4">💰</div>
                    <h3 class="text-xl font-bold text-white mb-3">Transparent Pricing</h3>
                    <p class="text-slate-400">Pay only for what you use with no hidden fees</p>
                </div>
                <div class="card-dark p-8">
                    <div class="feature-icon mb-4">🚀</div>
                    <h3 class="text-xl font-bold text-white mb-3">24/7 Support</h3>
                    <p class="text-slate-400">Expert support whenever you need us</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="py-20 px-4 sm:px-6 lg:px-8">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-16">
                <span class="badge-cyan mb-4 inline-block">Affordable Plans</span>
                <h2 class="text-4xl md:text-5xl font-bold mb-4">
                    <span class="text-white">Choose your best </span><span class="accent-green">pricing</span>
                </h2>
                <p class="text-slate-400 text-lg">Start free, scale as you grow</p>
            </div>

            @if($packages->count() > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    @foreach($packages as $index => $package)
                        @php
                            $isMostPopular = $package->featured || $index === floor(($packages->count() - 1) / 2);
                        @endphp
                        <div class="card-dark p-8 {{ $isMostPopular ? 'pricing-highlight relative' : '' }}">
                            @if($isMostPopular)
                                <div class="absolute -top-4 left-1/2 transform -translate-x-1/2">
                                    <span class="badge-cyan">Most Popular</span>
                                </div>
                            @endif
                            <h3 class="text-2xl font-bold text-white mb-2 {{ $isMostPopular ? 'mt-4' : '' }}">{{ $package->name }}</h3>
                            <p class="text-slate-400 text-sm mb-6">{{ $package->description }}</p>
                            <div class="mb-6">
                                <span class="text-4xl font-bold {{ $isMostPopular ? 'text-gradient' : 'text-white' }}">KES {{ number_format($package->monthly_price, 0) }}</span>
                                <span class="text-slate-400 text-sm">/month</span>
                            </div>
                            <button class="{{ $isMostPopular ? 'btn-cyan' : 'btn-outline' }} w-full mb-8">Get Started</button>
                            <div class="space-y-3 text-sm text-slate-300">
                                @if($package->features && is_array($package->features))
                                    @foreach($package->features as $feature)
                                        <p>✓ {{ $feature }}</p>
                                    @endforeach
                                @else
                                    <p>✓ Premium hosting features</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-12 text-slate-400">
                    <p>No hosting packages available at this time</p>
                </div>
            @endif
        </div>
    </section>

    <!-- Testimonials Section -->
    <section class="py-20 px-4 sm:px-6 lg:px-8">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="text-4xl md:text-5xl font-bold mb-4">
                    <span class="text-white">What our </span><span class="text-gradient">customers</span><span class="text-white"> are saying</span>
                </h2>
            </div>

            <div class="grid md:grid-cols-2 gap-8">
                <div class="card-dark p-8">
                    <p class="text-slate-300 mb-6">"I set up a fully-fledged production site with the reliable and fast features. What made me impressed is the support from a community of professionals"</p>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-cyan-600 flex items-center justify-center text-white font-bold">JS</div>
                        <div>
                            <p class="font-bold text-white">John Smith</p>
                            <p class="text-sm text-slate-400">CEO @ Tech Startup</p>
                        </div>
                    </div>
                </div>

                <div class="card-dark p-8">
                    <p class="text-slate-300 mb-6">"The container deployment feature saved us months of development time. Everything just works perfectly out of the box."</p>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-cyan-600 flex items-center justify-center text-white font-bold">MS</div>
                        <div>
                            <p class="font-bold text-white">Maria Santos</p>
                            <p class="text-sm text-slate-400">Product Manager</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Cart Sidebar -->
    <div x-show="cartCount > 0" x-transition class="fixed right-0 top-0 h-full w-full sm:w-96 bg-[#0F172A] border-l border-[rgba(0,217,255,0.2)] z-40 overflow-y-auto pt-24 sm:pt-0">
        <div class="sticky top-0 bg-[#0F172A]/95 backdrop-blur border-b border-[rgba(0,217,255,0.1)] p-6">
            <h3 class="text-xl font-bold text-white">Shopping Cart</h3>
            <p class="text-sm text-slate-400">
                <span x-text="cartCount"></span> item(s)
            </p>
        </div>

        <div class="p-6 space-y-4">
            <template x-for="domain in JSON.parse(localStorage.getItem('domainCart') || '[]')" :key="domain.full_domain">
                <div class="card-dark p-4">
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <div>
                            <p class="font-semibold text-white font-mono" x-text="domain.full_domain"></p>
                            <p class="text-xs text-slate-400 mt-1">
                                <span x-text="domain.years"></span> year(s) @ KES <span x-text="formatPrice(domain.price)"></span>/year
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="removeFromCart(domain.full_domain)"
                        class="w-full mt-3 px-3 py-2 bg-red-500/10 border border-red-500/50 text-red-400 rounded text-xs font-semibold hover:bg-red-500/20 transition"
                    >
                        Remove
                    </button>
                </div>
            </template>
        </div>

        <div class="sticky bottom-0 bg-[#0F172A]/95 backdrop-blur border-t border-[rgba(0,217,255,0.1)] p-6 space-y-4">
            <div class="space-y-2">
                <div class="flex justify-between text-slate-300 text-sm">
                    <span>Subtotal</span>
                    <span class="font-semibold">KES <span x-text="formatPrice(cartTotal)"></span></span>
                </div>
            </div>
            <button
                type="button"
                @click="goToCheckout()"
                class="btn-cyan w-full"
            >
                Checkout
            </button>
            <button
                type="button"
                @click="document.getElementById('domain-search').scrollIntoView()"
                class="w-full px-4 py-2 border border-[rgba(0,217,255,0.3)] text-cyan-400 rounded-lg font-semibold hover:border-cyan-400 transition text-sm"
            >
                Continue Shopping
            </button>
        </div>
    </div>

    <!-- Overlay -->
    <template x-if="cartCount > 0">
        <div
            @click="document.body.style.overflow = 'auto'"
            class="fixed inset-0 bg-black/50 z-30 lg:hidden"
        ></div>
    </template>

    <!-- Footer -->
    <footer class="border-t border-[rgba(0,217,255,0.1)] py-12 px-4 sm:px-6 lg:px-8 mt-20">
        <div class="max-w-7xl mx-auto">
            <div class="grid md:grid-cols-4 gap-12 mb-12">
                <div>
                    <div class="flex items-center gap-2 mb-4">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-cyan-400 to-cyan-600 flex items-center justify-center glow-cyan-sm">
                            <span class="text-[#0F172A] font-bold text-xs">TC</span>
                        </div>
                        <span class="font-bold text-white">Talksasa Cloud</span>
                    </div>
                    <p class="text-slate-400 text-sm">Enterprise hosting platform for the modern web.</p>
                </div>
                <div>
                    <h4 class="font-bold text-white mb-4">Product</h4>
                    <ul class="space-y-2 text-sm text-slate-400">
                        <li><a href="#" class="nav-link">Hosting</a></li>
                        <li><a href="#" class="nav-link">Domains</a></li>
                        <li><a href="#" class="nav-link">Containers</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold text-white mb-4">Company</h4>
                    <ul class="space-y-2 text-sm text-slate-400">
                        <li><a href="#" class="nav-link">About</a></li>
                        <li><a href="#" class="nav-link">Blog</a></li>
                        <li><a href="#" class="nav-link">Status</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold text-white mb-4">Legal</h4>
                    <ul class="space-y-2 text-sm text-slate-400">
                        <li><a href="#" class="nav-link">Privacy</a></li>
                        <li><a href="#" class="nav-link">Terms</a></li>
                        <li><a href="#" class="nav-link">Security</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-[rgba(0,217,255,0.1)] pt-8 flex items-center justify-between text-sm text-slate-400">
                <p>&copy; 2026 Talksasa Cloud. All rights reserved.</p>
                <div class="flex gap-6">
                    <a href="#" class="nav-link">Twitter</a>
                    <a href="#" class="nav-link">Discord</a>
                    <a href="#" class="nav-link">GitHub</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        function domainSearchApp() {
            return {
                searchQuery: '',
                results: [],
                loading: false,
                showResults: false,

                get availableDomains() {
                    return this.results.filter(r => r.available);
                },

                get unavailableDomains() {
                    return this.results.filter(r => !r.available);
                },

                get cartCount() {
                    const cart = JSON.parse(localStorage.getItem('domainCart') || '[]');
                    return cart.length;
                },

                get cartTotal() {
                    const cart = JSON.parse(localStorage.getItem('domainCart') || '[]');
                    return cart.reduce((sum, item) => sum + (item.price || 0), 0);
                },

                async searchDomain() {
                    if (!this.searchQuery.trim()) return;

                    this.loading = true;
                    this.results = [];

                    try {
                        const url = new URL('{{ route('domains.search.public') }}', window.location.origin);
                        url.searchParams.append('q', this.searchQuery);

                        const response = await fetch(url.toString(), {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const data = await response.json();

                        if (data.success) {
                            this.results = data.results;
                            this.showResults = true;
                            setTimeout(() => {
                                document.getElementById('domain-search').scrollIntoView({ behavior: 'smooth' });
                            }, 100);
                        } else {
                            this.showToast(data.message || 'Error searching domains', 'error');
                        }
                    } catch (error) {
                        console.error('Search error:', error);
                        this.showToast('Failed to search domains. Please try again.', 'error');
                    } finally {
                        this.loading = false;
                    }
                },

                isInCart(fullDomain) {
                    const cart = JSON.parse(localStorage.getItem('domainCart') || '[]');
                    return cart.some(d => d.full_domain === fullDomain);
                },

                addToCart(domain) {
                    const cart = JSON.parse(localStorage.getItem('domainCart') || '[]');

                    if (cart.find(d => d.full_domain === domain.full_domain)) {
                        this.showToast('Domain already in cart', 'error');
                        return;
                    }

                    cart.push(domain);
                    localStorage.setItem('domainCart', JSON.stringify(cart));
                    this.showToast(`${domain.full_domain} added to cart!`, 'success');
                },

                removeFromCart(fullDomain) {
                    const cart = JSON.parse(localStorage.getItem('domainCart') || '[]');
                    const filtered = cart.filter(d => d.full_domain !== fullDomain);
                    localStorage.setItem('domainCart', JSON.stringify(filtered));
                    this.showToast(`${fullDomain} removed from cart`, 'success');
                },

                async goToCheckout() {
                    const cart = JSON.parse(localStorage.getItem('domainCart') || '[]');

                    if (cart.length === 0) {
                        this.showToast('Your cart is empty', 'error');
                        return;
                    }

                    try {
                        // Sync cart to session
                        const response = await fetch('{{ route("checkout.sync-cart") }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ cart: cart })
                        });

                        if (response.ok) {
                            this.showToast('Redirecting to checkout...', 'success');
                            // Small delay to show the toast before redirecting
                            setTimeout(() => {
                                window.location.href = '{{ route("checkout.show.public") }}';
                            }, 500);
                        } else {
                            this.showToast('Failed to sync cart', 'error');
                        }
                    } catch (error) {
                        console.error('Sync error:', error);
                        this.showToast('Failed to sync cart', 'error');
                    }
                },

                formatPrice(price) {
                    return new Intl.NumberFormat('en-US', {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 0,
                    }).format(price);
                },

                showToast(message, type = 'info') {
                    const toast = document.createElement('div');
                    const classes = {
                        success: 'toast-success',
                        error: 'toast-error',
                        info: 'toast-info'
                    }[type];

                    toast.className = `toast ${classes}`;
                    toast.textContent = message;
                    document.body.appendChild(toast);

                    setTimeout(() => toast.remove(), 3000);
                }
            }
        }
    </script>
</body>
</html>
