<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Talksasa Cloud') }} - @yield('title', 'Dashboard')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="antialiased bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-50">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        @include('layouts.sidebar')

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <!-- Top Navigation -->
            @include('layouts.navbar')

            <!-- Page Content -->
            <div class="flex-1 overflow-auto">
                <div class="p-8 space-y-8">
                    @if (session('success'))
                        <div class="rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 p-4 flex items-start gap-3">
                            <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">{{ session('success') }}</p>
                            </div>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </div>
        </main>
    </div>

    @stack('scripts')
</body>
</html>
