@extends('layouts.customer')

@section('title', 'Security Settings')

@section('content')
<div class="space-y-6 max-w-4xl">
    <!-- Success Message -->
    @if (session('status') === 'sessions-cleared')
        <div
            x-data="{ show: true }"
            x-show="show"
            x-transition
            x-init="setTimeout(() => show = false, 5000)"
            class="flex items-center gap-3 px-4 py-3 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 rounded-lg"
        >
            <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            You have been signed out of all other sessions.
        </div>
    @endif

    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Security Settings</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your password and active sessions.</p>
    </div>

    <div class="space-y-6">
        <!-- Password Change Section -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            @include('profile.partials.update-password-form')
        </div>

        <!-- Active Sessions Section -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <section class="space-y-6">
                <header>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">
                        Active Sessions
                    </h2>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                        Manage your active login sessions on this and other devices.
                    </p>
                </header>

                <div class="space-y-4">
                    <!-- Current Session -->
                    <div class="flex items-start justify-between p-4 bg-slate-50 dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700">
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20m0 0l-.75 3M9 20H5m4 0h4m0 0l.75 3m-.75-3l1.68-5.25"/>
                                </svg>
                                <div>
                                    <p class="font-medium text-slate-900 dark:text-white">This Session</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">
                                        {{ request()->ip() }} • {{ now()->format('M d, Y H:i') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                            Active
                        </span>
                    </div>

                    <!-- Other Sessions Info -->
                    <div class="p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                        <p class="text-sm text-blue-800 dark:text-blue-200">
                            <strong>Note:</strong> You can view and manage all your active sessions. For security, we recommend signing out of sessions you no longer use.
                        </p>
                    </div>

                    <!-- Sign Out Other Sessions -->
                    <form method="post" action="{{ route('profile.logout-other-sessions') }}" class="pt-4 border-t border-slate-200 dark:border-slate-700">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-900 dark:text-white font-medium rounded-lg transition">
                            Sign Out Other Sessions
                        </button>
                    </form>
                </div>
            </section>
        </div>

        <!-- Login History Section (Optional - for future enhancement) -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <section class="space-y-6">
                <header>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">
                        Security Tips
                    </h2>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                        Keep your account secure with these best practices.
                    </p>
                </header>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Tip 1: Strong Password -->
                    <div class="p-4 bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-950/20 dark:to-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                        <div class="flex gap-3">
                            <svg class="w-6 h-6 text-blue-600 dark:text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                            <div>
                                <h3 class="font-medium text-blue-900 dark:text-blue-100">Use a Strong Password</h3>
                                <p class="text-sm text-blue-800 dark:text-blue-200 mt-1">
                                    Use at least 8 characters with letters, numbers, and symbols.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Tip 2: Regular Updates -->
                    <div class="p-4 bg-gradient-to-br from-emerald-50 to-emerald-100 dark:from-emerald-950/20 dark:to-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                        <div class="flex gap-3">
                            <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <div>
                                <h3 class="font-medium text-emerald-900 dark:text-emerald-100">Change Password Regularly</h3>
                                <p class="text-sm text-emerald-800 dark:text-emerald-200 mt-1">
                                    Update your password every 3-6 months.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Tip 3: Session Management -->
                    <div class="p-4 bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-950/20 dark:to-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-lg">
                        <div class="flex gap-3">
                            <svg class="w-6 h-6 text-purple-600 dark:text-purple-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                            </svg>
                            <div>
                                <h3 class="font-medium text-purple-900 dark:text-purple-100">Review Active Sessions</h3>
                                <p class="text-sm text-purple-800 dark:text-purple-200 mt-1">
                                    Regularly check and sign out unused sessions.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Tip 4: Verify Email -->
                    <div class="p-4 bg-gradient-to-br from-amber-50 to-amber-100 dark:from-amber-950/20 dark:to-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                        <div class="flex gap-3">
                            <svg class="w-6 h-6 text-amber-600 dark:text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            <div>
                                <h3 class="font-medium text-amber-900 dark:text-amber-100">Verify Your Email</h3>
                                <p class="text-sm text-amber-800 dark:text-amber-200 mt-1">
                                    Confirm your email address for account recovery.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
@endsection
