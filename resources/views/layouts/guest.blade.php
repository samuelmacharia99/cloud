<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data="{ dark: localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches) }" :class="{ 'dark': dark }">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Sign In') — {{ config('app.name', 'Talksasa Cloud') }}</title>

        @include('layouts.partials.fonts')

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    </head>
    <body class="font-sans antialiased app-shell">
        <div class="min-h-screen flex">
            {{-- Brand plane: ink + ember grid --}}
            <div class="hidden lg:flex lg:w-5/12 relative overflow-hidden text-ink-50 flex-col justify-between p-12"
                 style="background:
                    radial-gradient(ellipse 80% 50% at 100% 0%, rgb(245 158 11 / 0.35), transparent 55%),
                    radial-gradient(ellipse 60% 40% at 0% 100%, rgb(251 191 36 / 0.12), transparent 50%),
                    linear-gradient(165deg, #1c1917 0%, #0c0a09 100%);">
                <div class="absolute inset-0 opacity-40 pointer-events-none"
                     style="background-image:
                        linear-gradient(to right, rgb(255 255 255 / 0.04) 1px, transparent 1px),
                        linear-gradient(to bottom, rgb(255 255 255 / 0.04) 1px, transparent 1px);
                        background-size: 40px 40px;"></div>
                <div class="relative">
                    <a href="/" class="inline-flex items-center gap-3 mb-14">
                        <div class="brand-mark">
                            <span>TC</span>
                        </div>
                        <div>
                            <span class="font-display text-xl font-extrabold tracking-tight block leading-none">Talksasa</span>
                            <span class="text-[11px] uppercase tracking-[0.2em] text-brand-300/90 font-medium">Cloud</span>
                        </div>
                    </a>
                    <div class="space-y-6 mt-10 max-w-sm">
                        <h1 class="font-display text-4xl font-extrabold tracking-tighter leading-[1.1] text-balance">
                            Hosting that feels built, not bolted on.
                        </h1>
                        <p class="text-ink-300 text-base leading-relaxed">
                            Provision, bill, and run customer stacks from one rail — without the generic SaaS wallpaper.
                        </p>
                        <ul class="space-y-3 text-sm text-ink-200">
                            <li class="flex items-center gap-3">
                                <span class="w-1.5 h-1.5 rounded-full bg-brand-400 shrink-0"></span>
                                Billing &amp; provisioning in one flow
                            </li>
                            <li class="flex items-center gap-3">
                                <span class="w-1.5 h-1.5 rounded-full bg-brand-400 shrink-0"></span>
                                Live service and container control
                            </li>
                            <li class="flex items-center gap-3">
                                <span class="w-1.5 h-1.5 rounded-full bg-brand-400 shrink-0"></span>
                                Reseller-ready multi-tenant branding
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="relative text-xs text-ink-500 tracking-wide">
                    <p>© {{ date('Y') }} Talksasa Cloud</p>
                </div>
            </div>

            <div class="flex-1 flex flex-col justify-center items-center px-4 py-12 lg:w-7/12">
                <div class="w-full max-w-sm">
                    <div class="lg:hidden mb-8">
                        <a href="/" class="inline-flex items-center gap-3">
                            <div class="brand-mark"><span>TC</span></div>
                            <div>
                                <span class="font-display text-lg font-extrabold tracking-tight block leading-none text-ink-950 dark:text-white">Talksasa</span>
                                <span class="text-[10px] uppercase tracking-[0.2em] text-brand-600 dark:text-brand-400">Cloud</span>
                            </div>
                        </a>
                    </div>

                    <div class="ui-card p-6 sm:p-8">
                        @yield('content')
                    </div>
                </div>

                <button type="button" class="fixed bottom-8 right-8 p-2.5 rounded-xl border border-ink-200 dark:border-ink-700 bg-white/80 dark:bg-ink-900/80 backdrop-blur hover:bg-ink-50 dark:hover:bg-ink-800 transition" @click="dark = !dark; localStorage.setItem('theme', dark ? 'dark' : 'light')" aria-label="Toggle theme">
                    <svg class="w-5 h-5 text-ink-900 dark:text-ink-50" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="!dark">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                    </svg>
                    <svg class="w-5 h-5 text-ink-900 dark:text-ink-50" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="dark" x-cloak>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1m-16 0H1m15.364 1.636l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </button>
            </div>
        </div>
    </body>
</html>
