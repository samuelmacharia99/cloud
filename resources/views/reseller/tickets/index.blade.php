@extends('layouts.reseller')

@section('title', 'Support Tickets')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Support Tickets</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Your platform tickets and customer support requests.</p>
        </div>
        <a href="{{ route('reseller.tickets.create') }}" class="px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">New Ticket</a>
    </div>

    <div class="flex flex-wrap gap-2">
        <a href="{{ route('reseller.tickets.index', ['scope' => 'all']) }}"
           class="px-4 py-2 rounded-lg text-sm font-medium {{ ($scope ?? 'all') === 'all' ? 'bg-purple-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' }}">
            All
        </a>
        <a href="{{ route('reseller.tickets.index', ['scope' => 'mine']) }}"
           class="px-4 py-2 rounded-lg text-sm font-medium {{ ($scope ?? 'all') === 'mine' ? 'bg-purple-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' }}">
            My tickets
        </a>
        <a href="{{ route('reseller.tickets.index', ['scope' => 'customers']) }}"
           class="px-4 py-2 rounded-lg text-sm font-medium {{ ($scope ?? 'all') === 'customers' ? 'bg-purple-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' }}">
            Customer tickets
        </a>
    </div>

    @if ($tickets->count())
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Ticket</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Customer</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Handled by</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Status</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach ($tickets as $ticket)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                            <td class="px-6 py-4">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $ticket->title }}</p>
                                <p class="text-xs text-slate-500">#{{ $ticket->id }} · {{ $ticket->created_at->diffForHumans() }}</p>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                @if ($ticket->user_id === auth()->id())
                                    <span class="text-slate-500">You</span>
                                @else
                                    {{ $ticket->user?->name ?? 'N/A' }}
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                {{ $ticket->handled_by?->label() ?? 'Platform support' }}
                                @if ($ticket->isEscalated())
                                    <span class="ml-1 text-xs text-amber-600">(escalated)</span>
                                @endif
                            </td>
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
