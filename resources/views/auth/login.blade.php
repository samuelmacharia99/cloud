@extends('layouts.guest')

@section('title', 'Sign In')

<div class="space-y-5">
    <!-- Header -->
    <div>
        <h2 class="text-xl font-bold text-slate-900 dark:text-white">Welcome back</h2>
        <p class="text-xs text-slate-600 dark:text-slate-400 mt-0.5">Sign in to your account to continue</p>
    </div>

    <!-- Session Status -->
    @if (session('status'))
        <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-950 border border-emerald-200 dark:border-emerald-800 text-xs text-emerald-700 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    <!-- Form -->
    <form method="POST" action="{{ route('login') }}" class="space-y-3.5">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" value="Email Address" />
            <x-text-input
                id="email"
                class="block mt-2 w-full px-4 py-2.5"
                type="email"
                name="email"
                :value="old('email')"
                required
                autofocus
                autocomplete="username"
                placeholder="you@example.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div>
            <x-input-label for="password" value="Password" />
            <x-text-input
                id="password"
                class="block mt-2 w-full px-4 py-2.5"
                type="password"
                name="password"
                required
                autocomplete="current-password"
                placeholder="••••••••" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="flex items-center">
            <input
                id="remember"
                type="checkbox"
                class="rounded border-slate-300 dark:border-slate-600 text-blue-600 dark:text-blue-500 focus:ring-blue-500 dark:focus:ring-blue-400"
                name="remember">
            <label for="remember" class="ms-2 text-sm text-slate-700 dark:text-slate-300">
                Remember me
            </label>
        </div>

        <!-- Submit -->
        <x-primary-button class="w-full justify-center mt-4">
            {{ __('Sign In') }}
        </x-primary-button>
    </form>

    <!-- Divider -->
    <div class="relative pt-2">
        <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-slate-300 dark:border-slate-700"></div>
        </div>
        <div class="relative flex justify-center text-xs">
            <span class="px-2 bg-white dark:bg-slate-900 text-slate-400 dark:text-slate-500">or</span>
        </div>
    </div>

    <!-- Links -->
    <div class="space-y-2.5 text-center text-xs">
        @if (Route::has('password.request'))
            <div>
                <a href="{{ route('password.request') }}" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium transition">
                    Forgot your password?
                </a>
            </div>
        @endif

        @if (Route::has('register'))
            <div>
                <span class="text-slate-600 dark:text-slate-400">Don't have an account? </span>
                <a href="{{ route('register') }}" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium transition">
                    Create one
                </a>
            </div>
        @endif
    </div>
</div>
