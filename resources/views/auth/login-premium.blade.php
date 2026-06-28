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

    @if (session('error'))
        <div class="p-4 rounded-md bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800/40 text-xs text-red-700 dark:text-red-300 font-medium">
            {{ session('error') }}
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
                    <svg x-show="!showPassword" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    <svg x-show="showPassword" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
