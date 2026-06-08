@extends('layouts.auth-premium')

@section('title', 'Verify Email')

@section('content')
<div class="space-y-7">
    <!-- Header -->
    <div class="space-y-3">
        <h1 class="text-4xl font-bold tracking-tight">Verify Your Email</h1>
        <p class="text-base text-slate-600 dark:text-slate-400 font-medium">
            We sent a 6-digit code to <strong>{{ $email }}</strong>@if($phoneHint) and your phone ending in <strong>{{ $phoneHint }}</strong>@endif.
        </p>
    </div>

    <!-- Verification Form -->
    <form method="POST" action="{{ route('verification.code.verify') }}" class="space-y-5">
        @csrf

        <!-- Email (Hidden) -->
        <input type="hidden" name="email" value="{{ $email }}">

        <!-- Verification Code -->
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
                class="auth-input text-center text-2xl tracking-widest font-mono"
                required
                autofocus
            />
            @error('code')
                <p class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <!-- Submit Button -->
        <button type="submit" class="auth-btn-primary w-full">
            Verify Email
        </button>
    </form>

    <!-- Resend Code -->
    <form method="POST" action="{{ route('verification.code.resend') }}" class="pt-2">
        @csrf
        <input type="hidden" name="email" value="{{ $email }}">
        <button type="submit" class="text-sm text-purple-600 dark:text-purple-400 hover:underline">
            Didn't receive the code? Resend
        </button>
    </form>

    @if (session('success'))
    <div class="p-3 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900/30 rounded-lg text-sm text-emerald-700 dark:text-emerald-200">
        {{ session('success') }}
    </div>
    @endif
</div>
@endsection
