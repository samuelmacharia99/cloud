@extends('layouts.auth-premium')

@section('title', 'Create Account')

@php
    $passwordMinLength = config('security.password.min_length', 8);
@endphp

@section('content')
<div
    x-data="{
        showPassword: false,
        showConfirmPassword: false,
        generatingPassword: false,
        async generatePassword() {
            this.generatingPassword = true;
            try {
                const res = await fetch('{{ route('register.generate-password') }}?length=16', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                if (!res.ok) throw new Error('Failed');
                const data = await res.json();
                if (!data.password) throw new Error('Empty');
                document.getElementById('password').value = data.password;
                document.getElementById('password_confirmation').value = data.password;
                this.showPassword = true;
                this.showConfirmPassword = true;
            } catch {
                alert('Could not generate a password. Please try again or enter one manually.');
            } finally {
                this.generatingPassword = false;
            }
        }
    }"
    x-init="@if ($errors->any()) $nextTick(() => document.querySelector('.auth-field-error, .auth-input-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' })) @endif"
    class="space-y-7"
>
    <!-- Header -->
    <div class="space-y-3 mb-2">
        <h1 class="text-4xl font-bold tracking-tight">Create your account</h1>
        <p class="text-base text-slate-600 dark:text-slate-400 font-medium">Start managing your infrastructure in minutes</p>
    </div>

    @if ($errors->any())
        <div class="auth-field-error rounded-lg p-4 text-sm" role="alert">
            <p class="font-semibold mb-2">We couldn't create your account yet:</p>
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Registration Form -->
    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        <input type="hidden" name="registration_token" value="{{ $registrationToken ?? session('registrationToken') ?? old('registration_token', '') }}">

        {{-- Honeypot: leave empty; bots and autofill often fill hidden fields --}}
        <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
            <input
                type="text"
                name="{{ config('registration.honeypot_field', 'contact_website') }}"
                value=""
                tabindex="-1"
                autocomplete="off"
            >
        </div>

        <!-- Name -->
        <div class="grid grid-cols-2 gap-3">
            <div class="space-y-2.5 min-w-0">
                <label for="first_name" class="block text-sm font-semibold text-slate-900 dark:text-white">
                    First name
                </label>
                <input
                    type="text"
                    id="first_name"
                    name="first_name"
                    value="{{ old('first_name') }}"
                    required
                    autofocus
                    autocomplete="given-name"
                    placeholder="Jane"
                    class="auth-input"
                />
                @error('first_name')
                    <p class="mt-1.5 text-xs font-medium auth-input-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2.5 min-w-0">
                <label for="last_name" class="block text-sm font-semibold text-slate-900 dark:text-white">
                    Last name
                    <span class="font-normal text-slate-500 dark:text-slate-400 text-xs">(optional)</span>
                </label>
                <input
                    type="text"
                    id="last_name"
                    name="last_name"
                    value="{{ old('last_name') }}"
                    autocomplete="family-name"
                    placeholder="Doe"
                    class="auth-input"
                />
                @error('last_name')
                    <p class="mt-1.5 text-xs font-medium auth-input-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <x-country-select
            name="country"
            label="Country"
            :value="old('country')"
            :required="true"
            variant="auth"
            placeholder="Select your country"
        />

        <!-- Company (Optional) -->
        <div class="space-y-2.5">
            <label for="company" class="block text-sm font-semibold text-slate-900 dark:text-white">
                Company
                <span class="font-normal text-slate-500 text-xs ml-1">(optional)</span>
            </label>
            <input
                type="text"
                id="company"
                name="company"
                value="{{ old('company') }}"
                autocomplete="organization"
                placeholder="Acme Inc."
                class="auth-input"
            />
            @error('company')
                <p class="mt-1.5 text-xs font-medium auth-input-error">{{ $message }}</p>
            @enderror
        </div>

        <!-- Email Address -->
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
                autocomplete="email"
                placeholder="me@company.com"
                class="auth-input"
            />
            @error('email')
                <p class="mt-1.5 text-xs font-medium auth-input-error">{{ $message }}</p>
            @enderror
        </div>

        <!-- Password -->
        <div class="space-y-2.5">
            <div class="flex items-center justify-between gap-3">
                <label for="password" class="block text-sm font-semibold text-slate-900 dark:text-white">
                    Password
                </label>
                <button
                    type="button"
                    @click="generatePassword()"
                    :disabled="generatingPassword"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300 disabled:opacity-50 transition"
                >
                    <svg class="w-4 h-4" :class="{ 'animate-spin': generatingPassword }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <span x-text="generatingPassword ? 'Generating…' : 'Generate password'"></span>
                </button>
            </div>
            <div class="relative" style="overflow: hidden; height: 2.75rem;">
                <input
                    :type="showPassword ? 'text' : 'password'"
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
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
                    <svg x-show="!showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    <svg x-show="showPassword" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-4.753 4.753m5.596-3.856a3.375 3.375 0 01-4.753 4.753M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 9.172M15.828 15.828m-7.071-7.071L15.828 15.828M9.172 15.828L15.828 9.172"></path>
                    </svg>
                </button>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                At least {{ $passwordMinLength }} characters with uppercase, lowercase, numbers, and symbols.
            </p>
            @error('password')
                <p class="mt-1.5 text-xs font-medium auth-input-error">{{ $message }}</p>
            @enderror
        </div>

        <!-- Confirm Password -->
        <div class="space-y-2.5">
            <label for="password_confirmation" class="block text-sm font-semibold text-slate-900 dark:text-white">
                Confirm password
            </label>
            <div class="relative" style="overflow: hidden; height: 2.75rem;">
                <input
                    :type="showConfirmPassword ? 'text' : 'password'"
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="••••••••"
                    class="auth-input pr-11"
                    style="padding-right: 2.75rem !important;"
                />
                <button
                    type="button"
                    @click="showConfirmPassword = !showConfirmPassword"
                    class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors"
                    aria-label="Toggle confirm password visibility"
                >
                    <svg x-show="!showConfirmPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    <svg x-show="showConfirmPassword" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-4.753 4.753m5.596-3.856a3.375 3.375 0 01-4.753 4.753M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 9.172M15.828 15.828m-7.071-7.071L15.828 15.828M9.172 15.828L15.828 9.172"></path>
                    </svg>
                </button>
            </div>
            @error('password_confirmation')
                <p class="mt-1.5 text-xs font-medium auth-input-error">{{ $message }}</p>
            @enderror
        </div>

        <!-- Terms Checkbox -->
        <div class="flex items-start gap-3 pt-1">
            <input
                id="agree"
                type="checkbox"
                name="agree"
                value="1"
                required
                @checked(old('agree'))
                class="w-4 h-4 mt-1.5 rounded-md border-slate-300 dark:border-slate-600 text-purple-600 dark:text-purple-500 focus:ring-0 focus:border-purple-500 transition cursor-pointer flex-shrink-0"
            >
            <label for="agree" class="text-xs text-slate-700 dark:text-slate-300 font-medium leading-relaxed cursor-pointer">
                I agree to the <a href="{{ route('terms') }}" target="_blank" class="text-purple-600 dark:text-purple-400 hover:underline transition font-semibold">Terms of Service</a> and <a href="{{ route('privacy') }}" target="_blank" class="text-purple-600 dark:text-purple-400 hover:underline transition font-semibold">Privacy Policy</a>
            </label>
        </div>
        @error('agree')
            <p class="mt-1.5 text-xs font-medium auth-input-error">{{ $message }}</p>
        @enderror
        @error('registration_token')
            <p class="mt-1.5 text-xs font-medium auth-input-error">{{ $message }}</p>
        @enderror

        <!-- Sign Up Button -->
        <button type="submit" class="auth-btn-primary mt-2">
            Create account
        </button>
    </form>

    <!-- Sign In Link -->
    <div class="pt-1 text-center text-sm">
        <span class="text-slate-600 dark:text-slate-400 font-medium">
            Already have an account?
        </span>
        @if (Route::has('login'))
            <a href="{{ route('login') }}" class="font-semibold text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300 transition">
                Sign in
            </a>
        @endif
    </div>
</div>

@endsection
