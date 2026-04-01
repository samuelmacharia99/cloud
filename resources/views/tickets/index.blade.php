@extends('layouts.app')

@section('title', 'Support Tickets')

@section('content')
<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Support Tickets</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Track your support requests and get help.</p>
        </div>
        <a href="{{ route('tickets.create') }}" class="px-6 py-2.5 rounded-lg bg-blue-600 dark:bg-blue-500 text-white text-sm font-medium hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
            + Create Ticket
        </a>
    </div>

    <!-- Tickets Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Ticket</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Priority</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Status</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Created</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($tickets as $ticket)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="px-6 py-4">
                                <p class="font-semibold text-slate-900 dark:text-white">{{ $ticket->title }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-500">#{{ $ticket->id }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium {{ $ticket->priority === 'urgent' || $ticket->priority === 'high' ? 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-200' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' }}">
                                    {{ ucfirst($ticket->priority) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium {{ $ticket->status === 'closed' ? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' : 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-200' }}">
                                    {{ ucfirst($ticket->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600 dark:text-slate-400">{{ $ticket->created_at->format('M d, Y') }}</p>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('tickets.show', $ticket) }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <p class="text-slate-500 dark:text-slate-400">No tickets found</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($tickets->hasPages())
        <div class="flex items-center justify-center">
            {{ $tickets->links() }}
        </div>
    @endif
</div>
@endsection
