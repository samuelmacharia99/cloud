@extends('layouts.auth-premium')

@section('title', 'Reset Password')

@section('content')
<div class="space-y-7">
    <!-- Header -->
    <div class="space-y-2">
        <h1 class="text-4xl font-bold tracking-tight">Forgot your password?</h1>
        <p class="text-base text-slate-600 dark:text-slate-400 font-medium">Enter your email and we'll send you a reset link</p>
    </div>

    <!-- Session Status -->
    @if (session('status'))
        <div class="p-4 rounded-md bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/40 text-xs text-emerald-700 dark:text-emerald-300 font-medium">
            {{ session('status') }}
        </div>
    @endif

    <!-- Reset Link Form -->
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
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

        <!-- Send Button -->
        <button type="submit" class="auth-btn-primary mt-2">
            Send reset link
        </button>
    </form>

    <!-- Back to Login -->
    <div class="text-center text-sm">
        <a href="{{ route('login') }}" class="font-semibold text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300 transition">
            Back to sign in
        </a>
    </div>
</div>
@endsection
