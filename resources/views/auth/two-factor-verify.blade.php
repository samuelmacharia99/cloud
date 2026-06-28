@extends('layouts.auth-premium')

@section('title', 'Two-Factor Authentication')

@section('content')
<div class="space-y-7">
    <div class="space-y-3 text-center">
        <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900/40 rounded-full flex items-center justify-center mx-auto">
            <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
        </div>
        <h1 class="text-4xl font-bold tracking-tight">Two-Factor Authentication</h1>
        <p class="text-base text-slate-600 dark:text-slate-400 font-medium">
            Enter the 6-digit code sent to your phone
        </p>
    </div>

    <form method="POST" action="{{ route('auth.two-factor.verify-code') }}" class="space-y-5">
        @csrf

        <div class="space-y-2.5">
            <label for="code" class="block text-sm font-semibold text-slate-900 dark:text-white">
                Verification Code
            </label>
            <input
                type="text"
                id="code"
                name="code"
                maxlength="6"
                placeholder="000000"
                inputmode="numeric"
                autocomplete="one-time-code"
                autofocus
                class="auth-input text-center text-2xl tracking-widest font-mono"
            />
            @error('code')
                <p class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="auth-btn-primary w-full">
            Verify Code
        </button>
    </form>

    <div class="relative">
        <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-slate-200 dark:border-slate-700/50"></div>
        </div>
        <div class="relative flex justify-center">
            <span class="px-3 bg-white dark:bg-slate-900 text-xs font-semibold text-slate-500 dark:text-slate-400">Or use a recovery code</span>
        </div>
    </div>

    <form method="POST" action="{{ route('auth.two-factor.use-recovery-code') }}" class="space-y-4">
        @csrf

        <div class="space-y-2.5">
            <label for="recovery_code" class="block text-sm font-semibold text-slate-900 dark:text-white">
                Recovery Code
            </label>
            <input
                type="text"
                id="recovery_code"
                name="recovery_code"
                placeholder="XXXXXXXX"
                autocomplete="off"
                class="auth-input font-mono uppercase"
            />
            <p class="text-xs text-slate-500 dark:text-slate-400">Use this if you don't have access to your phone</p>
            @error('recovery_code')
                <p class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="auth-btn-secondary w-full">
            Use Recovery Code
        </button>
    </form>

    <p class="text-xs text-slate-500 dark:text-slate-400 text-center">
        Code expires in <strong>5 minutes</strong>.
        <a href="{{ route('login') }}" class="text-purple-600 dark:text-purple-400 hover:underline font-semibold">
            Try again
        </a>
    </p>
</div>
@endsection
