<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data="{ dark: localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches), sidebarOpen: false }" :class="{ 'dark': dark }">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Reseller Dashboard') — {{ config('app.name', 'Talksasa Cloud') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    </head>
    <body class="font-sans antialiased bg-white dark:bg-slate-950 text-slate-900 dark:text-white">
        <div class="min-h-screen flex">
            <!-- Sidebar -->
            <aside class="w-64 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 overflow-y-auto hidden md:flex md:flex-col fixed h-screen md:static">
                <!-- Logo -->
                <div class="h-16 flex items-center px-6 border-b border-slate-200 dark:border-slate-800">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-purple-500 to-purple-600 flex items-center justify-center">
                            <span class="text-white font-bold text-sm">TC</span>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-900 dark:text-white">Talksasa</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Reseller</p>
                        </div>
                    </a>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 px-3 py-6 space-y-8">
                    <!-- Dashboard -->
                    <div class="space-y-1">
                        <a href="{{ route('dashboard') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all {{ request()->routeIs('dashboard') ? 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-3m0 0l7-4 7 4M5 9v10a1 1 0 001 1h12a1 1 0 001-1V9m-9 4l4 2m-7-2l4-2"/>
                            </svg>
                            <span class="text-sm font-medium">Dashboard</span>
                        </a>
                    </div>

                    <!-- Management -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-purple-500 dark:text-purple-400 uppercase tracking-wider">Management</p>
                        <a href="{{ route('reseller.customers.index') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all {{ request()->routeIs('reseller.customers.*') ? 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.856-1.487M15 10h.01M10 10a4 4 0 11-8 0 4 4 0 018 0zM9 20H3v-2a6 6 0 0112 0v2z"/>
                            </svg>
                            <span class="text-sm font-medium">My Customers</span>
                        </a>
                        <a href="{{ route('reseller.catalog.index') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all {{ request()->routeIs('reseller.catalog.*') ? 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m0 0l8-4m0 0l8 4m0 0v10l-8 4m0-10L4 7m0 10v10l8 4m8-4v-10l-8-4"/>
                            </svg>
                            <span class="text-sm font-medium">My Catalog</span>
                        </a>
                        <a href="{{ route('reseller.domains.pricing') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all {{ request()->routeIs('reseller.domains.*') ? 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                            </svg>
                            <span class="text-sm font-medium">Domain Pricing</span>
                        </a>
                    </div>

                    <!-- Reseller -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Reseller</p>
                        <a href="{{ route('reseller.packages.index') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all {{ request()->routeIs('reseller.packages.*') ? 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m0 0l8-4m0 0l8 4m0 0v10l-8 4m0-10L4 7m0 10v10l8 4m8-4v-10l-8-4"/>
                            </svg>
                            <span class="text-sm font-medium">My Package</span>
                        </a>
                    </div>

                    <!-- Account -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Account</p>
                        <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all {{ request()->routeIs('profile.edit') ? 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4z"/>
                            </svg>
                            <span class="text-sm font-medium">Profile</span>
                        </a>
                        <a href="{{ route('profile.security') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all {{ request()->routeIs('profile.security') ? 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                            <span class="text-sm font-medium">Security</span>
                        </a>
                    </div>

                    <!-- Support -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Help</p>
                        <a href="{{ route('customer.tickets.index') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all {{ request()->routeIs('customer.tickets.*') ? 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                            <span class="text-sm font-medium">Support</span>
                        </a>
                    </div>
                </nav>

                <!-- Account Status Footer -->
                <div class="p-4 border-t border-slate-200 dark:border-slate-800">
                    <div class="flex items-center gap-2 px-3 py-2">
                        <div class="w-2 h-2 rounded-full bg-purple-500"></div>
                        <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Reseller Active</span>
                    </div>
                </div>

                <!-- Sign Out -->
                <div class="p-3 border-t border-slate-200 dark:border-slate-800">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                            <span class="text-sm font-medium">Sign Out</span>
                        </button>
                    </form>
                </div>
            </aside>

            <!-- Mobile Sidebar Backdrop -->
            <div x-show="sidebarOpen" class="fixed inset-0 bg-black/50 md:hidden z-40" @click="sidebarOpen = false"></div>

            <!-- Main Content -->
            <div class="flex-1 md:ml-0 flex flex-col">
                <!-- Top Navigation -->
                <header class="h-16 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 flex items-center px-6">
                    <!-- Left: Hamburger + Title -->
                    <div class="flex items-center gap-4">
                        <button class="md:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg" @click="sidebarOpen = !sidebarOpen">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>
                        <h1 class="hidden sm:block text-lg font-semibold">@yield('title')</h1>
                    </div>

                    <!-- Right: Dark Mode + Account -->
                    <div class="flex items-center gap-4 ml-auto">
                        <!-- Dark Mode Toggle Switch -->
                        <div class="flex items-center gap-2 px-2 py-1.5 bg-slate-100 dark:bg-slate-800 rounded-lg">
                            <svg class="w-4 h-4 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1m-16 0H1m15.364 1.636l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                            <button @click="dark = !dark; localStorage.setItem('theme', dark ? 'dark' : 'light')" class="relative w-10 h-6 bg-slate-300 dark:bg-slate-600 rounded-full transition-colors focus:outline-none">
                                <span :class="dark ? 'translate-x-5' : 'translate-x-0'" class="absolute top-0.5 left-0.5 w-5 h-5 bg-white dark:bg-slate-900 rounded-full transition-transform shadow"></span>
                            </button>
                            <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 015.646 5.646 9.001 9.001 0 0120.354 15.354z"/>
                            </svg>
                        </div>

                        <!-- Account Dropdown -->
                        <div class="relative" x-data="{ accountOpen: false }">
                            <button @click="accountOpen = !accountOpen" class="flex items-center gap-2 p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center text-white text-xs font-semibold">
                                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                                </div>
                                <div class="hidden lg:block text-left">
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">{{ auth()->user()->name }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Reseller</p>
                                </div>
                                <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="!accountOpen">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                                </svg>
                                <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="accountOpen">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7 7 7"/>
                                </svg>
                            </button>

                            <!-- Dropdown Menu -->
                            <div x-show="accountOpen" @click.outside="accountOpen = false" class="absolute right-0 mt-2 w-56 bg-white dark:bg-slate-900 rounded-lg shadow-lg border border-slate-200 dark:border-slate-800 overflow-hidden z-50" style="display: none">
                                <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800">
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">{{ auth()->user()->name }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ auth()->user()->email }}</p>
                                </div>
                                <a href="{{ route('profile.edit') }}" class="block px-4 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">
                                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/></svg>
                                    Profile
                                </a>
                                <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-200 dark:border-slate-800">
                                    @csrf
                                    <button type="submit" class="w-full text-left px-4 py-2.5 text-sm text-red-600 dark:text-red-400 hover:bg-slate-100 dark:hover:bg-slate-800">
                                        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                        Sign Out
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </header>

                <!-- Flash Messages -->
                @if (session('success'))
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                    x-transition:leave="transition ease-in duration-300"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-2"
                    class="mx-6 mt-4 flex items-center gap-3 px-4 py-3 bg-emerald-50 dark:bg-emerald-950 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 rounded-lg">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-sm font-medium flex-1">{{ session('success') }}</p>
                    <button @click="show = false" class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-800 dark:hover:text-emerald-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                @endif
                @if (session('error'))
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 7000)"
                    x-transition:leave="transition ease-in duration-300"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-2"
                    class="mx-6 mt-4 flex items-center gap-3 px-4 py-3 bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 rounded-lg">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 4v2m0-14a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-sm font-medium flex-1">{{ session('error') }}</p>
                    <button @click="show = false" class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                @endif

                <!-- Page Content -->
                <main class="flex-1 overflow-auto">
                    <div class="px-6 py-8 max-w-7xl mx-auto w-full">
                        @yield('content')
                    </div>
                </main>
            </div>
        </div>

        @stack('scripts')
    </body>
</html>
