<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data="{ dark: localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches) }" :class="{ 'dark': dark }">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Sign In') — {{ config('app.name', 'Talksasa Cloud') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    </head>
    <body class="font-sans antialiased bg-white dark:bg-slate-950 text-slate-900 dark:text-white">
        <div class="min-h-screen flex">
            <!-- Left Brand Panel (hidden on mobile) -->
            <div class="hidden lg:flex lg:w-5/12 bg-gradient-to-br from-blue-600 to-blue-800 text-white flex-col justify-between p-12">
                <div>
                    <a href="/" class="inline-flex items-center gap-2 mb-12">
                        <div class="w-10 h-10 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center">
                            <span class="text-white font-bold">TC</span>
                        </div>
                        <div class="font-bold">
                            <span class="text-lg">Talksasa</span>
                            <span class="text-xs block opacity-90">Cloud</span>
                        </div>
                    </a>
                    <div class="space-y-8 mt-12">
                        <div>
                            <h1 class="text-4xl font-bold mb-4">Modern Web Hosting Platform</h1>
                            <p class="text-blue-100">Manage your entire web hosting business with one powerful platform.</p>
                        </div>
                        <ul class="space-y-4 text-sm text-blue-100">
                            <li class="flex items-center gap-3">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Streamlined billing and provisioning</span>
                            </li>
                            <li class="flex items-center gap-3">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Real-time service management</span>
                            </li>
                            <li class="flex items-center gap-3">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Powerful analytics and reporting</span>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="text-xs text-blue-100">
                    <p>© {{ date('Y') }} Talksasa Cloud. All rights reserved.</p>
                </div>
            </div>

            <!-- Right Form Panel -->
            <div class="flex-1 flex flex-col justify-center items-center px-4 py-12 lg:w-7/12">
                <div class="w-full max-w-sm">
                    <!-- Mobile Logo (visible on mobile only) -->
                    <div class="lg:hidden mb-6">
                        <a href="/" class="inline-flex items-center gap-2">
                            <x-application-logo />
                        </a>
                    </div>

                    <!-- Form Content -->
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 shadow-md">
                        @yield('content')
                    </div>
                </div>

                <!-- Dark Mode Toggle -->
                <button class="fixed bottom-8 right-8 p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition" @click="dark = !dark; localStorage.setItem('theme', dark ? 'dark' : 'light')">
                    <svg class="w-5 h-5 text-slate-900 dark:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="!dark">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 015.646 5.646 9.001 9.001 0 0120.354 15.354z"/>
                    </svg>
                    <svg class="w-5 h-5 text-slate-900 dark:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="dark">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1m-16 0H1m15.364 1.636l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </button>
            </div>
        </div>
    </body>
</html>
