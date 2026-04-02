@extends('layouts.guest')

@section('title', 'Verify Email')

<div class="space-y-6">
    <!-- Header -->
    <div class="text-center">
        <div class="w-16 h-16 rounded-full bg-blue-100 dark:bg-blue-950 flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
        </div>
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Verify your email</h2>
        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2">We've sent a confirmation link to your email address. Click the link to verify your account.</p>
    </div>

    <!-- Status Messages -->
    @if (session('status') == 'verification-link-sent')
        <div class="p-4 rounded-lg bg-emerald-50 dark:bg-emerald-950 border border-emerald-200 dark:border-emerald-800 text-sm text-emerald-700 dark:text-emerald-300">
            <p class="font-medium">✓ Verification link sent</p>
            <p class="text-xs mt-1">Check your email for the confirmation link. It may take a few moments to arrive.</p>
        </div>
    @endif

    <!-- Help Text -->
    <div class="p-4 rounded-lg bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 text-sm text-blue-700 dark:text-blue-300">
        <p class="font-medium">Didn't receive the email?</p>
        <p class="text-xs mt-1">Check your spam folder, or request a new verification link below.</p>
    </div>

    <!-- Actions -->
    <div class="space-y-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-primary-button class="w-full justify-center">
                {{ __('Resend Verification Email') }}
            </x-primary-button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="w-full px-4 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg font-medium text-sm transition">
                {{ __('Sign Out') }}
            </button>
        </form>
    </div>
</div>
