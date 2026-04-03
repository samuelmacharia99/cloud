@extends('layouts.auth-premium')

@section('title', 'Sign In')

@section('content')
<div x-data="{ showPassword: false }" class="space-y-7">
    <!-- Header -->
    <div class="space-y-2">
        <h1 class="text-4xl font-bold tracking-tight">Welcome back</h1>
        <p class="text-base text-slate-600 dark:text-slate-400 font-medium">Sign in to manage your infrastructure</p>
    </div>

    <!-- Session Status -->
    @if (session('status'))
        <div class="p-4 rounded-md bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/40 text-xs text-emerald-700 dark:text-emerald-300 font-medium">
            {{ session('status') }}
        </div>
    @endif

    <!-- Login Form -->
    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <!-- Email Input -->
        <div class="space-y-2.5">
            <label for="email" class="block text-sm font-semibold text-slate-900 dark:text-white">
                Email address
            </label>
            <input
                type="email"
                id="email"
                name="email"
                value="{{ old('email') }}"
                required
                autofocus
                autocomplete="email"
                placeholder="me@company.com"
                class="auth-input"
            />
            @error('email')
                <p class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <!-- Password Input with Visibility Toggle -->
        <div class="space-y-2.5">
            <div class="flex items-center justify-between">
                <label for="password" class="block text-sm font-semibold text-slate-900 dark:text-white">
                    Password
                </label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs font-semibold text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300 transition">
                        Forgot?
                    </a>
                @endif
            </div>
            <div class="relative" style="overflow: hidden; height: 2.75rem;">
                <input
                    :type="showPassword ? 'text' : 'password'"
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="••••••••"
                    class="auth-input pr-11"
                    style="padding-right: 2.75rem !important;"
                />
                <button
                    type="button"
                    @click="showPassword = !showPassword"
                    class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors"
                    aria-label="Toggle password visibility"
                >
                    <svg v-if="!showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-4.753 4.753m5.596-3.856a3.375 3.375 0 01-4.753 4.753M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 9.172M15.828 15.828m-7.071-7.071L15.828 15.828M9.172 15.828L15.828 9.172"></path>
                    </svg>
                </button>
            </div>
            @error('password')
                <p class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <!-- Remember Me -->
        <div class="flex items-center gap-2.5 pt-1">
            <input
                id="remember"
                type="checkbox"
                name="remember"
                class="w-4 h-4 rounded-md border-slate-300 dark:border-slate-600 text-purple-600 dark:text-purple-500 focus:ring-0 focus:border-purple-500 transition cursor-pointer"
            >
            <label for="remember" class="text-sm text-slate-700 dark:text-slate-300 font-medium cursor-pointer">
                Remember me for 30 days
            </label>
        </div>

        <!-- Sign In Button -->
        <button type="submit" class="auth-btn-primary mt-2">
            Sign in
        </button>
    </form>

    <!-- Divider -->
    <div class="relative">
        <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-slate-200 dark:border-slate-700/50"></div>
        </div>
        <div class="relative flex justify-center">
            <span class="px-3 bg-white dark:bg-slate-950 text-xs font-semibold text-slate-500 dark:text-slate-400">Or continue with</span>
        </div>
    </div>

    <!-- Social Login Buttons -->
    <div class="grid grid-cols-2 gap-3">
        <!-- Google -->
        <button type="button" class="auth-btn-secondary inline-flex items-center justify-center gap-2">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="currentColor"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="currentColor"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="currentColor"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="currentColor"/>
            </svg>
            <span class="hidden sm:inline text-xs font-semibold">Google</span>
        </button>

        <!-- GitHub -->
        <button type="button" class="auth-btn-secondary inline-flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v 3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
            </svg>
            <span class="hidden sm:inline text-xs font-semibold">GitHub</span>
        </button>
    </div>

    <!-- Sign Up Link -->
    <div class="pt-1 text-center text-sm">
        <span class="text-slate-600 dark:text-slate-400 font-medium">
            New here?
        </span>
        @if (Route::has('register'))
            <a href="{{ route('register') }}" class="font-semibold text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300 transition">
                Create account
            </a>
        @endif
    </div>
</div>
@endsection
