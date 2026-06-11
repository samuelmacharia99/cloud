@extends('layouts.reseller')

@section('title', 'Ticket #'.$ticket->id)

@section('content')
<div class="space-y-6 max-w-4xl">
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('reseller.tickets.index') }}" class="text-sm text-purple-600 hover:text-purple-700">← Back to tickets</a>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white mt-2">{{ $ticket->title }}</h1>
            <p class="text-sm text-slate-500">Opened by {{ $ticket->user?->name }} · <x-status-badge :status="$ticket->status" type="ticket" /></p>
        </div>
        @if (!$ticket->isClosed())
            <form action="{{ route('reseller.tickets.close', $ticket) }}" method="POST" data-confirm='Close this ticket?'>
                @csrf
                @method('PATCH')
                <button type="submit" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">Close Ticket</button>
            </form>
        @endif
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <p class="text-slate-700 dark:text-slate-300 whitespace-pre-wrap">{{ $ticket->description }}</p>
        <x-ticket-attachments :attachments="$ticket->attachments" :ticket="$ticket" route-name="reseller.tickets.attachments.show" />
    </div>

    <div class="space-y-4">
        @foreach ($ticket->replies as $reply)
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4">
                <p class="text-xs text-slate-500 mb-2">{{ $reply->user?->name }} · {{ $reply->created_at->diffForHumans() }}</p>
                <p class="text-slate-700 dark:text-slate-300 whitespace-pre-wrap">{{ $reply->message }}</p>
                <x-ticket-attachments :attachments="$reply->attachments" :ticket="$ticket" route-name="reseller.tickets.attachments.show" />
            </div>
        @endforeach
    </div>

    @if (!$ticket->isClosed())
        <form action="{{ route('reseller.tickets.reply', $ticket) }}" method="POST" enctype="multipart/form-data" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
            @csrf
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">Reply</label>
            <textarea name="message" rows="4" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg"></textarea>
            <x-ticket-attachment-input />
            <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg">Send Reply</button>
        </form>
    @endif
</div>
@endsection
