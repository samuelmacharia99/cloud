@extends('layouts.app')

@section('title', 'Ticket #' . $ticket->id)

@section('content')
<div class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex justify-between items-start">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">{{ $ticket->title }}</h1>
                    <p class="mt-2 text-gray-600 dark:text-gray-400">Ticket #{{ $ticket->id }} · Created {{ $ticket->created_at->diffForHumans() }}</p>
                </div>
                <a href="{{ route('customer.tickets.index') }}" class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white">
                    ← Back to Tickets
                </a>
            </div>

            <!-- Status Card -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Status</h3>
                        <span class="mt-2 inline-block px-3 py-1 rounded-full text-sm font-semibold
                            @if($ticket->status === 'open') bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200
                            @elseif($ticket->status === 'in_progress') bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200
                            @elseif($ticket->status === 'on_hold') bg-purple-100 dark:bg-purple-900 text-purple-800 dark:text-purple-200
                            @else bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200
                            @endif">
                            {{ ucfirst(str_replace('_', ' ', $ticket->status)) }}
                        </span>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Priority</h3>
                        <span class="mt-2 inline-block px-3 py-1 rounded-full text-sm font-semibold
                            @if($ticket->priority === 'urgent') bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200
                            @elseif($ticket->priority === 'high') bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200
                            @elseif($ticket->priority === 'medium') bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200
                            @else bg-slate-100 dark:bg-slate-700 text-slate-800 dark:text-slate-200
                            @endif">
                            {{ ucfirst($ticket->priority) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Ticket Details -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <p class="text-gray-700 dark:text-gray-300 whitespace-pre-line">{{ $ticket->description }}</p>
                <div class="mt-6 pt-6 border-t border-slate-200 dark:border-slate-700 text-sm text-gray-600 dark:text-gray-400">
                    Created {{ $ticket->created_at->format('M d, Y H:i') }}
                </div>
            </div>

            <!-- Replies Thread -->
            <div class="space-y-4">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Replies ({{ $ticket->replies->count() }})</h2>

                @forelse($ticket->replies as $reply)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 {{ $reply->is_staff_reply ? 'border-blue-300 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20' : '' }}">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white">
                                {{ $reply->user->name }}
                                @if($reply->is_staff_reply)
                                <span class="text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 px-2 py-1 rounded-full ml-2">Support Team</span>
                                @endif
                            </p>
                        </div>
                        <span class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $reply->created_at->format('M d, Y H:i') }}
                        </span>
                    </div>
                    <p class="text-gray-700 dark:text-gray-300 whitespace-pre-line">{{ $reply->message }}</p>
                </div>
                @empty
                <p class="text-gray-600 dark:text-gray-400 text-center py-8">No replies yet.</p>
                @endforelse
            </div>

            <!-- Reply Form -->
            @if($ticket->isOpen())
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Add Reply</h3>
                <form action="{{ route('customer.tickets.reply', $ticket) }}" method="POST" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Message</label>
                        <textarea
                            name="message"
                            required
                            rows="5"
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500"
                            placeholder="Type your reply..."
                        ></textarea>
                        @error('message')
                        <p class="text-red-600 dark:text-red-400 text-sm mt-2">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition-colors">
                        Send Reply
                    </button>
                </form>
            </div>
            @endif

            <!-- Close Ticket Form -->
            @can('close', $ticket)
            @if(!$ticket->isClosed())
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Close Ticket</h3>
                <form action="{{ route('customer.tickets.close', $ticket) }}" method="POST" onsubmit="return confirm('Are you sure you want to close this ticket?');">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg font-medium transition-colors">
                        Close Ticket
                    </button>
                </form>
            </div>
            @endif
            @endcan
        </div>
    </div>
</div>
@endsection
