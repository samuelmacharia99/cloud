@extends('layouts.reseller')

@section('title', 'Ticket #'.$ticket->id)

@section('content')
<div class="space-y-6 max-w-4xl">
    <div class="flex items-center justify-between gap-4">
        <div>
            <a href="{{ route('reseller.tickets.index') }}" class="text-sm text-purple-600 hover:text-purple-700">← Back to tickets</a>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white mt-2">{{ $ticket->title }}</h1>
            <p class="text-sm text-slate-500">
                Opened by {{ $ticket->user?->name }}
                · {{ $ticket->handled_by?->label() ?? 'Platform support' }}
                · <x-status-badge :status="$ticket->status" type="ticket" />
            </p>
            @if ($ticket->isEscalated())
                <p class="text-sm text-amber-600 mt-1">
                    Escalated to platform {{ $ticket->escalated_at?->diffForHumans() }}
                    @if ($ticket->escalatedByUser)
                        by {{ $ticket->escalatedByUser->name }}
                    @endif
                </p>
            @endif
        </div>
        <div class="flex items-center gap-2">
            @if (!$ticket->isClosed())
                <form action="{{ route('reseller.tickets.close', $ticket) }}" method="POST" data-confirm='Close this ticket?'>
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">Close Ticket</button>
                </form>
            @endif
        </div>
    </div>

    @if ($canEscalate ?? false)
        <div class="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-2xl p-6">
            <h2 class="text-lg font-semibold text-amber-900 dark:text-amber-100">Push to platform support</h2>
            <p class="text-sm text-amber-800 dark:text-amber-200 mt-1">
                Escalate this ticket when you need help from the platform team. Admins will be notified and can take over.
            </p>
            <form action="{{ route('reseller.tickets.escalate', $ticket) }}" method="POST" class="mt-4 space-y-3" data-confirm="Escalate this ticket to platform support?">
                @csrf
                <textarea name="escalation_note" rows="3" placeholder="Optional note for the platform team..."
                    class="w-full px-4 py-2 border border-amber-300 dark:border-amber-700 bg-white dark:bg-slate-900 rounded-lg text-sm"></textarea>
                <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-sm font-medium">
                    Escalate to platform
                </button>
            </form>
        </div>
    @endif

    @if (filled($ticket->escalation_note))
        <div class="bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Escalation note</p>
            <p class="text-sm text-slate-700 dark:text-slate-300 mt-1 whitespace-pre-wrap">{{ $ticket->escalation_note }}</p>
        </div>
    @endif

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
