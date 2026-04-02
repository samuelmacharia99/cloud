@extends('layouts.guest')

@section('title', 'Create Account')

<div class="space-y-6">
    <!-- Header -->
    <div>
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Create your account</h2>
        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Join Talksasa Cloud and start managing your services</p>
    </div>

    <!-- Form -->
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <!-- Full Name -->
        <div>
            <x-input-label for="name" value="Full Name" />
            <x-text-input
                id="name"
                class="block mt-2 w-full px-4 py-2.5"
                type="text"
                name="name"
                :value="old('name')"
                required
                autofocus
                autocomplete="name"
                placeholder="John Doe" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Company (Optional) -->
        <div>
            <x-input-label for="company" value="Company (Optional)" />
            <x-text-input
                id="company"
                class="block mt-2 w-full px-4 py-2.5"
                type="text"
                name="company"
                :value="old('company')"
                autocomplete="organization"
                placeholder="Acme Inc." />
            <x-input-error :messages="$errors->get('company')" class="mt-2" />
        </div>

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
                autocomplete="new-password"
                placeholder="••••••••" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div>
            <x-input-label for="password_confirmation" value="Confirm Password" />
            <x-text-input
                id="password_confirmation"
                class="block mt-2 w-full px-4 py-2.5"
                type="password"
                name="password_confirmation"
                required
                autocomplete="new-password"
                placeholder="••••••••" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <!-- Terms -->
        <div class="flex items-start">
            <input
                id="agree"
                type="checkbox"
                class="mt-1 rounded border-slate-300 dark:border-slate-600 text-blue-600 dark:text-blue-500 focus:ring-blue-500 dark:focus:ring-blue-400"
                name="agree"
                required>
            <label for="agree" class="ms-2 text-sm text-slate-700 dark:text-slate-300">
                I agree to the <a href="#" class="text-blue-600 dark:text-blue-400 hover:underline">Terms of Service</a> and <a href="#" class="text-blue-600 dark:text-blue-400 hover:underline">Privacy Policy</a>
            </label>
        </div>

        <!-- Submit -->
        <x-primary-button class="w-full justify-center">
            {{ __('Create Account') }}
        </x-primary-button>
    </form>

    <!-- Divider -->
    <div class="relative">
        <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-slate-300 dark:border-slate-700"></div>
        </div>
        <div class="relative flex justify-center text-xs">
            <span class="px-2 bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400">or</span>
        </div>
    </div>

    <!-- Sign In Link -->
    <div class="text-center text-sm">
        <span class="text-slate-600 dark:text-slate-400">Already have an account? </span>
        <a href="{{ route('login') }}" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium transition">
            Sign in
        </a>
    </div>
</div>
