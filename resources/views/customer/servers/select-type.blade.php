@extends('layouts.customer')

@section('title', 'Select Server Type')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 max-w-2xl w-full p-8 md:p-12">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white mb-2">Select Server Type</h1>
            <p class="text-slate-600 dark:text-slate-400">Choose the type of server you'd like to view or purchase</p>
        </div>

        <!-- Options Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- VPS Option -->
            <a href="{{ route('customer.servers.index', ['type' => 'vps']) }}" class="group relative overflow-hidden rounded-xl border-2 border-slate-200 dark:border-slate-700 p-8 text-center transition-all hover:border-blue-500 dark:hover:border-blue-400 hover:shadow-lg dark:hover:bg-slate-800/50">
                <div class="relative z-10">
                    <!-- Icon -->
                    <div class="flex justify-center mb-6">
                        <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900/30 rounded-2xl flex items-center justify-center group-hover:bg-blue-200 dark:group-hover:bg-blue-900/50 transition">
                            <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Title -->
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-3">VPS Server</h2>

                    <!-- Description -->
                    <p class="text-slate-600 dark:text-slate-400 mb-6 text-sm leading-relaxed">
                        Virtual Private Servers with dedicated resources. Perfect for applications requiring more power and flexibility.
                    </p>

                    <!-- Features -->
                    <div class="space-y-2 text-sm mb-6">
                        <div class="flex items-center justify-center gap-2 text-slate-600 dark:text-slate-400">
                            <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                            Scalable Resources
                        </div>
                        <div class="flex items-center justify-center gap-2 text-slate-600 dark:text-slate-400">
                            <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                            Root Access
                        </div>
                        <div class="flex items-center justify-center gap-2 text-slate-600 dark:text-slate-400">
                            <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                            Custom Configuration
                        </div>
                    </div>

                    <!-- CTA -->
                    <div class="inline-block px-6 py-2 bg-blue-600 group-hover:bg-blue-700 text-white font-medium rounded-lg transition">
                        View VPS Servers
                    </div>
                </div>

                <!-- Background gradient -->
                <div class="absolute inset-0 bg-gradient-to-br from-blue-50 to-transparent dark:from-blue-900/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
            </a>

            <!-- Dedicated Server Option -->
            <a href="{{ route('customer.servers.index', ['type' => 'dedicated_server']) }}" class="group relative overflow-hidden rounded-xl border-2 border-slate-200 dark:border-slate-700 p-8 text-center transition-all hover:border-purple-500 dark:hover:border-purple-400 hover:shadow-lg dark:hover:bg-slate-800/50">
                <div class="relative z-10">
                    <!-- Icon -->
                    <div class="flex justify-center mb-6">
                        <div class="w-16 h-16 bg-purple-100 dark:bg-purple-900/30 rounded-2xl flex items-center justify-center group-hover:bg-purple-200 dark:group-hover:bg-purple-900/50 transition">
                            <svg class="w-8 h-8 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Title -->
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-3">Dedicated Server</h2>

                    <!-- Description -->
                    <p class="text-slate-600 dark:text-slate-400 mb-6 text-sm leading-relaxed">
                        Entire servers dedicated to your business. Maximum performance and control for demanding applications.
                    </p>

                    <!-- Features -->
                    <div class="space-y-2 text-sm mb-6">
                        <div class="flex items-center justify-center gap-2 text-slate-600 dark:text-slate-400">
                            <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                            Full Server Resources
                        </div>
                        <div class="flex items-center justify-center gap-2 text-slate-600 dark:text-slate-400">
                            <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                            Premium Performance
                        </div>
                        <div class="flex items-center justify-center gap-2 text-slate-600 dark:text-slate-400">
                            <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                            Enterprise Support
                        </div>
                    </div>

                    <!-- CTA -->
                    <div class="inline-block px-6 py-2 bg-purple-600 group-hover:bg-purple-700 text-white font-medium rounded-lg transition">
                        View Dedicated Servers
                    </div>
                </div>

                <!-- Background gradient -->
                <div class="absolute inset-0 bg-gradient-to-br from-purple-50 to-transparent dark:from-purple-900/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
            </a>
        </div>

        <!-- Back button -->
        <div class="text-center mt-8">
            <a href="{{ route('dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition">
                ← Back to Dashboard
            </a>
        </div>
    </div>
</div>
@endsection
