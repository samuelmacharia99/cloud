@extends('layouts.customer')

@section('title', 'Transfer Status - ' . $domain->name . $domain->extension)

@section('breadcrumb')
<div class="flex items-center gap-2">
    <a href="{{ route('customer.domains.index') }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
        Domains
    </a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Transfer Status</p>
</div>
@endsection

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $domain->name }}{{ $domain->extension }}</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-2">Domain Transfer Status & Details</p>
    </div>

    <!-- Status Summary Card -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <!-- Status Header -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-blue-100 mb-1">Transfer Status</p>
                    <h2 class="text-2xl font-bold">{{ $domain->getTransferStatusLabel() }}</h2>
                </div>
                <div class="text-right">
                    <p class="text-sm text-blue-100">Initiated</p>
                    <p class="font-semibold">{{ $domain->transfer_initiated_at?->format('M d, Y') ?? 'Pending' }}</p>
                </div>
            </div>
        </div>

        <!-- Status Details -->
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-6">
                <div>
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase mb-1">Domain</p>
                    <p class="text-lg font-medium text-slate-900 dark:text-white">{{ $domain->name }}{{ $domain->extension }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase mb-1">Current Registrar</p>
                    <p class="text-lg font-medium text-slate-900 dark:text-white">{{ $domain->old_registrar }}</p>
                </div>
            </div>

            @if($domain->old_registrar_url)
                <div>
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase mb-1">Registrar Website</p>
                    <a href="{{ $domain->old_registrar_url }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 font-medium">
                        {{ $domain->old_registrar_url }}
                        <svg class="w-4 h-4 inline-block ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4m-4-6l6 6m0 0l-6 6m6-6H5"/>
                        </svg>
                    </a>
                </div>
            @endif
        </div>
    </div>

    <!-- Timeline -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-6">Transfer Timeline</h3>

        <div class="space-y-6">
            <!-- Step 1: Request Submitted -->
            <div class="flex gap-4">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center h-10 w-10 rounded-full bg-green-100 dark:bg-green-900">
                        <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                </div>
                <div>
                    <p class="font-semibold text-slate-900 dark:text-white">Transfer Request Submitted</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">{{ $domain->created_at->format('M d, Y H:i') }}</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">Your transfer request has been received and is being processed.</p>
                </div>
            </div>

            <!-- Step 2: Awaiting Authorization -->
            <div class="flex gap-4">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center h-10 w-10 rounded-full" :class="['{{ in_array($domain->transfer_status, ['initiated', 'in_progress', 'completed']) ? 'bg-green-100 dark:bg-green-900' : 'bg-slate-200 dark:bg-slate-700' }}'">
                        @if(in_array($domain->transfer_status, ['initiated', 'in_progress', 'completed']))
                            <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        @else
                            <svg class="h-6 w-6 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        @endif
                    </div>
                </div>
                <div>
                    <p class="font-semibold text-slate-900 dark:text-white">Awaiting Registrar Authorization</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                        @if($domain->transfer_initiated_at)
                            Initiated: {{ $domain->transfer_initiated_at->format('M d, Y H:i') }}
                        @else
                            Pending authorization from your current registrar
                        @endif
                    </p>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">Your current registrar will send you an authorization request. Please approve it to proceed with the transfer.</p>
                </div>
            </div>

            <!-- Step 3: Transfer In Progress -->
            <div class="flex gap-4">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center h-10 w-10 rounded-full" :class="['{{ $domain->transfer_status === 'in_progress' ? 'bg-blue-100 dark:bg-blue-900' : ($domain->transfer_status === 'completed' ? 'bg-green-100 dark:bg-green-900' : 'bg-slate-200 dark:bg-slate-700') }}'">
                        @if($domain->transfer_status === 'completed')
                            <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        @else
                            <svg class="h-6 w-6" :class="['{{ $domain->transfer_status === 'in_progress' ? 'text-blue-600 dark:text-blue-400 animate-spin' : 'text-slate-500 dark:text-slate-400' }}'}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        @endif
                    </div>
                </div>
                <div>
                    <p class="font-semibold text-slate-900 dark:text-white">Transfer In Progress</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">3-5 business days</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">Once authorized, your domain will be transferred to our registrar. This process typically takes 3-5 business days.</p>
                </div>
            </div>

            <!-- Step 4: Transfer Complete -->
            <div class="flex gap-4">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center h-10 w-10 rounded-full" :class="['{{ $domain->transfer_status === 'completed' ? 'bg-green-100 dark:bg-green-900' : 'bg-slate-200 dark:bg-slate-700' }}'">
                        @if($domain->transfer_status === 'completed')
                            <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        @else
                            <svg class="h-6 w-6 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        @endif
                    </div>
                </div>
                <div>
                    <p class="font-semibold text-slate-900 dark:text-white">Transfer Complete</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                        @if($domain->transfer_completed_at)
                            Completed: {{ $domain->transfer_completed_at->format('M d, Y H:i') }}
                        @else
                            Estimated: {{ $estimatedCompletion?->format('M d, Y') ?? 'Pending' }}
                        @endif
                    </p>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">Your domain will be fully transferred and ready to use with our registrar.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Instructions -->
    @if($domain->isTransferPending() || $domain->isTransferInitiated())
        <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6">
            <h3 class="text-lg font-bold text-blue-900 dark:text-blue-300 mb-4">What You Need To Do</h3>
            <ol class="space-y-3 list-decimal list-inside">
                @foreach($instructions ?? [] as $index => $instruction)
                    <li class="text-sm text-blue-800 dark:text-blue-300">
                        {{ $instruction }}
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    <!-- Warning -->
    <div class="bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl p-6">
        <div class="flex gap-3">
            <svg class="w-6 h-6 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <div class="text-sm text-amber-900 dark:text-amber-300">
                <strong>Important:</strong> Do not renew your domain with your current registrar during the transfer process. This can cancel the transfer and you may lose your domain registration.
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="flex gap-3">
        <a href="{{ route('customer.domains.index') }}" class="flex-1 px-6 py-3 bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white font-semibold rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition text-center">
            Back to Domains
        </a>

        @if($domain->isTransferPending() || $domain->isTransferInitiated())
            <form action="{{ route('customer.domains.cancel-transfer', $domain) }}" method="POST" class="flex-1" onsubmit="return confirm('Are you sure you want to cancel this transfer? This action cannot be undone.');">
                @csrf
                @method('POST')
                <button type="submit" class="w-full px-6 py-3 bg-red-100 dark:bg-red-900/20 text-red-700 dark:text-red-400 font-semibold rounded-lg hover:bg-red-200 dark:hover:bg-red-900/40 transition">
                    Cancel Transfer
                </button>
            </form>
        @endif
    </div>

    <!-- FAQ -->
    <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-6">
        <h3 class="font-semibold text-slate-900 dark:text-white mb-4">Common Questions</h3>
        <div class="space-y-4">
            <div>
                <h4 class="font-medium text-slate-900 dark:text-white mb-1">How long does a transfer take?</h4>
                <p class="text-sm text-slate-600 dark:text-slate-400">Transfers typically complete within 3-5 business days, depending on your current registrar's processing time.</p>
            </div>
            <div>
                <h4 class="font-medium text-slate-900 dark:text-white mb-1">Will my domain go offline?</h4>
                <p class="text-sm text-slate-600 dark:text-slate-400">No, your domain will remain online and fully functional throughout the transfer process. There is no downtime.</p>
            </div>
            <div>
                <h4 class="font-medium text-slate-900 dark:text-white mb-1">What if my registrar doesn't authorize?</h4>
                <p class="text-sm text-slate-600 dark:text-slate-400">If your current registrar doesn't authorize the transfer, we'll notify you. You can then contact them directly or try the transfer again.</p>
            </div>
            <div>
                <h4 class="font-medium text-slate-900 dark:text-white mb-1">Do I need to do anything after transfer?</h4>
                <p class="text-sm text-slate-600 dark:text-slate-400">Once the transfer is complete, you can manage your domain directly from your account. Your domain registration is automatically renewed for 1 year as part of the transfer.</p>
            </div>
        </div>
    </div>
</div>
@endsection
