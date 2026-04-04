@extends('layouts.admin')

@section('title', 'Emails')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Emails</p>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Email Log</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">View all emails sent from the system.</p>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Sent Today</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $totalSentToday }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Failed Today</p>
            <p class="text-3xl font-bold text-red-600 dark:text-red-400 mt-2">{{ $totalFailedToday }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Sent</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $totalAllTime }}</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="flex gap-2 flex-wrap">
            <a href="{{ route('admin.emails.index') }}" class="px-4 py-2 rounded-lg font-medium text-sm transition-all {{ $status === 'all' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                All Emails
            </a>
            <a href="{{ route('admin.emails.index', ['status' => 'sent']) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition-all {{ $status === 'sent' ? 'bg-green-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                Sent
            </a>
            <a href="{{ route('admin.emails.index', ['status' => 'failed']) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition-all {{ $status === 'failed' ? 'bg-red-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                Failed
            </a>
            <a href="{{ route('admin.emails.index', ['status' => 'bounced']) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition-all {{ $status === 'bounced' ? 'bg-amber-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                Bounced
            </a>
        </div>
    </div>

    <!-- Email Table -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Recipient</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Subject</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Sent By</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    @forelse ($emails as $email)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4 text-sm text-slate-900 dark:text-white">{{ $email->recipient }}</td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300 truncate max-w-xs">{{ $email->subject }}</td>
                            <td class="px-6 py-4 text-sm">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium {{ $email->status === 'sent' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : ($email->status === 'failed' ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300') }}">
                                    {{ ucfirst($email->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                {{ $email->sentBy?->name ?? 'System' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                {{ $email->created_at->format('M d, Y H:i') }}
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <a href="{{ route('admin.emails.show', $email) }}" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium">
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
                                <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                                <p class="text-sm font-medium">No emails found</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if ($emails->hasPages())
            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-700">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-slate-600 dark:text-slate-400">
                        Showing <span class="font-medium">{{ $emails->firstItem() }}</span> to <span class="font-medium">{{ $emails->lastItem() }}</span> of <span class="font-medium">{{ $emails->total() }}</span> emails
                    </div>
                    <div class="flex gap-2">
                        @if ($emails->onFirstPage())
                            <span class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Previous</span>
                        @else
                            <a href="{{ $emails->previousPageUrl() }}" class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-700">← Previous</a>
                        @endif

                        @if ($emails->hasMorePages())
                            <a href="{{ $emails->nextPageUrl() }}" class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-700">Next →</a>
                        @else
                            <span class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</span>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
