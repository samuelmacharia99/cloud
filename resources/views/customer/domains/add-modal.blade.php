@extends('layouts.customer')

@section('title', 'Add Domain')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-xl">
        <!-- Header -->
        <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Add Domain</h2>
            <a href="{{ route('customer.domains.index') }}" class="text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </a>
        </div>

        <!-- Content -->
        <div class="p-6">
            <p class="text-slate-600 dark:text-slate-400 mb-8">Choose how you'd like to add a domain:</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Register New Domain -->
                <a href="{{ route('customer.select-techstack') }}" class="cursor-pointer p-6 border-2 border-slate-200 dark:border-slate-700 rounded-xl hover:border-blue-500 dark:hover:border-blue-400 hover:bg-blue-50 dark:hover:bg-blue-950/20 transition block">
                    <div class="flex items-center justify-center w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-950 mb-4">
                        <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                    </div>
                    <h3 class="font-bold text-slate-900 dark:text-white mb-2">Register New Domain</h3>
                    <p class="text-sm text-slate-600 dark:text-slate-400">
                        Register a brand new domain with us. Choose from hundreds of extensions.
                    </p>
                </a>

                <!-- Transfer Domain -->
                <a href="{{ route('customer.domains.transfer-form') }}" class="cursor-pointer p-6 border-2 border-slate-200 dark:border-slate-700 rounded-xl hover:border-emerald-500 dark:hover:border-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/20 transition block">
                    <div class="flex items-center justify-center w-12 h-12 rounded-lg bg-emerald-100 dark:bg-emerald-950 mb-4">
                        <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m0 0l4 4m10-4v12m0 0l4-4m0 0l-4-4"/>
                        </svg>
                    </div>
                    <h3 class="font-bold text-slate-900 dark:text-white mb-2">Transfer Domain</h3>
                    <p class="text-sm text-slate-600 dark:text-slate-400">
                        Transfer an existing domain from another registrar to us.
                    </p>
                </a>
            </div>
        </div>

        <!-- Footer -->
        <div class="p-6 border-t border-slate-200 dark:border-slate-800 flex items-center justify-end gap-3">
            <a href="{{ route('customer.domains.index') }}" class="px-6 py-2 text-slate-700 dark:text-slate-300 font-medium hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition">
                Cancel
            </a>
        </div>
    </div>
</div>
@endsection
