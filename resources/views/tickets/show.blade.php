@extends('layouts.app')

@section('title', 'Ticket #' . $ticket->id)

@section('content')
<div class="space-y-8">
    <div>
        <a href="{{ route('tickets.index') }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">← Back to tickets</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Thread -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Ticket Header -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
                <div class="flex items-start justify-between mb-6">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">{{ $ticket->title }}</h1>
                        <p class="text-slate-600 dark:text-slate-400 mt-1">Ticket #{{ $ticket->id }}</p>
                    </div>
                    <span class="px-3 py-1 rounded-full text-sm font-medium {{ $ticket->status === 'closed' ? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' : 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-200' }}">
                        {{ ucfirst($ticket->status) }}
                    </span>
                </div>

                <p class="text-slate-700 dark:text-slate-300 whitespace-pre-wrap">{{ $ticket->description }}</p>

                <div class="mt-6 pt-6 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between text-sm text-slate-600 dark:text-slate-400">
                    <span>{{ $ticket->user->name }} • {{ $ticket->created_at->format('M d, Y \a\t h:i A') }}</span>
                    <span class="px-2 py-1 rounded bg-slate-100 dark:bg-slate-800">{{ ucfirst($ticket->priority) }} Priority</span>
                </div>
            </div>

            <!-- Replies -->
            <div class="space-y-4">
                @foreach ($ticket->replies as $reply)
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-xs font-bold">
                                {{ strtoupper(substr($reply->user->name, 0, 1)) }}
                            </div>
                            <div>
                                <p class="font-semibold text-slate-900 dark:text-white">{{ $reply->user->name }}</p>
                                <p class="text-xs text-slate-600 dark:text-slate-400">
                                    {{ $reply->created_at->format('M d, Y \a\t h:i A') }}
                                    @if ($reply->is_staff_reply)
                                        <span class="ml-2 px-2 py-0.5 rounded bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-200 text-xs font-medium">Staff</span>
                                    @endif
                                </p>
                            </div>
                        </div>
                        <p class="text-slate-700 dark:text-slate-300 whitespace-pre-wrap">{{ $reply->message }}</p>
                    </div>
                @endforeach
            </div>

            <!-- Reply Form -->
            @if ($ticket->status !== 'closed')
                <form action="{{ route('tickets.reply', $ticket) }}" method="POST" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Your Reply</label>
                        <textarea name="message" rows="4" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Type your reply..."></textarea>
                    </div>
                    <button type="submit" class="px-6 py-2 rounded-lg bg-blue-600 dark:bg-blue-500 text-white font-medium hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
                        Send Reply
                    </button>
                </form>
            @endif
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Ticket Info -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="font-semibold text-slate-900 dark:text-white mb-4">Ticket Info</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-xs text-slate-600 dark:text-slate-400 uppercase font-semibold mb-1">Status</p>
                        <p class="text-slate-900 dark:text-white">{{ ucfirst($ticket->status) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-600 dark:text-slate-400 uppercase font-semibold mb-1">Priority</p>
                        <p class="text-slate-900 dark:text-white">{{ ucfirst($ticket->priority) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-600 dark:text-slate-400 uppercase font-semibold mb-1">Created</p>
                        <p class="text-slate-900 dark:text-white">{{ $ticket->created_at->format('M d, Y') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-600 dark:text-slate-400 uppercase font-semibold mb-1">Replies</p>
                        <p class="text-slate-900 dark:text-white">{{ $ticket->replies->count() }}</p>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            @if ($ticket->status !== 'closed')
                <form action="{{ route('tickets.close', $ticket) }}" method="POST">
                    @csrf
                    <button type="submit" class="w-full px-4 py-2 rounded-lg bg-slate-600 dark:bg-slate-700 text-white font-medium hover:bg-slate-700 dark:hover:bg-slate-600 transition-colors">
                        Close Ticket
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
