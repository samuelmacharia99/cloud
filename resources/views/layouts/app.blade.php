<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data="{ dark: localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches), sidebarOpen: false }" @keydown.escape="sidebarOpen = false" :class="{ 'dark': dark }">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Dashboard') - {{ config('app.name', 'Talksasa Cloud') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    </head>
    <body class="font-sans antialiased bg-white dark:bg-slate-950 text-slate-900 dark:text-white">
        <div class="min-h-screen flex min-w-0">
            <!-- Mobile Sidebar Backdrop -->
            <div x-cloak x-show="sidebarOpen" class="fixed inset-0 bg-black/50 md:hidden z-40" @click="sidebarOpen = false"></div>

            <!-- Sidebar -->
            @include('layouts.sidebar')

            <!-- Main Content -->
            <div class="flex-1 flex flex-col min-w-0">
                <!-- Navbar -->
                @include('layouts.navbar')

                <!-- Page Content -->
                <main class="flex-1 overflow-auto min-w-0">
                    <div class="px-4 py-6 sm:px-6 sm:py-8 max-w-7xl mx-auto w-full">
                        @yield('content')
                    </div>
                </main>
            </div>
        </div>

        @stack('scripts')
        <x-app-dialog />
    </body>
</html>
