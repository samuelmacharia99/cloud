@extends('layouts.admin')

@section('title', 'Email Details')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.emails.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Emails</a>
    <span class="text-slate-400">/</span>
    <p class="text-slate-600 dark:text-slate-400">{{ $email->subject }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $email->subject }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Email details and content</p>
        </div>
        <a href="{{ route('admin.emails.index') }}" class="px-4 py-2 text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition">
            ← Back
        </a>
    </div>

    <!-- Details Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Recipient</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white mt-2 break-all">{{ $email->recipient }}</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Status</p>
            <div class="mt-2">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $email->status === 'sent' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : ($email->status === 'failed' ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300') }}">
                    {{ ucfirst($email->status) }}
                </span>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Sent By</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white mt-2">
                @if ($email->sentBy)
                    <a href="{{ route('admin.customers.show', $email->sentBy) }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                        {{ $email->sentBy->name }}
                    </a>
                @else
                    <span class="text-slate-500">System</span>
                @endif
            </p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Sent At</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white mt-2">{{ $email->created_at->format('M d, Y H:i') }}</p>
        </div>
    </div>

    <!-- Email Body -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
        <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Message Content</h2>
        <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-6 text-slate-700 dark:text-slate-300 whitespace-pre-wrap break-words text-sm leading-relaxed max-h-96 overflow-y-auto">
            {{ $email->body }}
        </div>
    </div>

    <!-- Response (if failed) -->
    @if ($email->status !== 'sent' && $email->response)
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Error Response</h2>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-6 text-red-700 dark:text-red-300 font-mono text-sm overflow-x-auto border border-red-200 dark:border-red-800 max-h-64 overflow-y-auto">
                {{ $email->response }}
            </div>
        </div>
    @endif
</div>
@endsection
