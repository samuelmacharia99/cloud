@extends('layouts.guest')

@section('title', 'Reset Password')

<div class="space-y-6">
    <!-- Header -->
    <div>
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Reset your password</h2>
        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Enter your email address and we'll send you a link to reset your password</p>
    </div>

    <!-- Session Status -->
    @if (session('status'))
        <div class="p-4 rounded-lg bg-emerald-50 dark:bg-emerald-950 border border-emerald-200 dark:border-emerald-800 text-sm text-emerald-700 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    <!-- Form -->
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
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
                placeholder="you@example.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Submit -->
        <x-primary-button class="w-full justify-center">
            {{ __('Send Reset Link') }}
        </x-primary-button>
    </form>

    <!-- Back Link -->
    <div class="text-center text-sm">
        <a href="{{ route('login') }}" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium transition">
            Back to sign in
        </a>
    </div>
</div>
