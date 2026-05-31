@extends('layouts.reseller')

@section('title', 'Support Tickets')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Support Tickets</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Your tickets and customer support requests.</p>
        </div>
        <a href="{{ route('reseller.tickets.create') }}" class="px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">New Ticket</a>
    </div>

    @if ($tickets->count())
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Ticket</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Customer</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Status</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach ($tickets as $ticket)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                            <td class="px-6 py-4">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $ticket->title }}</p>
                                <p class="text-xs text-slate-500">{{ $ticket->created_at->diffForHumans() }}</p>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $ticket->user?->name ?? 'N/A' }}</td>
                            <td class="px-6 py-4"><x-status-badge :status="$ticket->status" type="ticket" /></td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('reseller.tickets.show', $ticket) }}" class="text-purple-600 hover:text-purple-700 text-sm font-medium">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $tickets->links() }}
    @else
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-12 text-center text-slate-500">No tickets yet.</div>
    @endif
</div>
@endsection
