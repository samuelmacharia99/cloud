@extends('layouts.customer')

@section('title', 'Support Tickets')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap justify-between items-center gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Support Tickets</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">View and manage your support requests</p>
        </div>
        <a href="{{ route('customer.tickets.create') }}" class="btn-primary">Create ticket</a>
    </div>

    <form method="GET" class="ui-card p-4 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Status</label>
            <select name="status" class="form-select text-sm">
                <option value="all" @selected(request('status', 'all') === 'all')>All</option>
                @foreach (['open', 'in_progress', 'on_hold', 'closed'] as $s)
                    <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Priority</label>
            <select name="priority" class="form-select text-sm">
                <option value="all" @selected(request('priority', 'all') === 'all')>All</option>
                @foreach (['low', 'medium', 'high', 'urgent'] as $p)
                    <option value="{{ $p }}" @selected(request('priority') === $p)>{{ ucfirst($p) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Title or description..." class="form-input text-sm w-full">
        </div>
        <button type="submit" class="btn-primary btn-sm">Filter</button>
        @if(request()->hasAny(['status', 'priority', 'search']))
            <a href="{{ route('customer.tickets.index') }}" class="btn-secondary btn-sm">Clear</a>
        @endif
    </form>

    <div class="ui-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-semibold">ID</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Title</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Status</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Priority</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Replies</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Created</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse($tickets as $ticket)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-6 py-4 text-sm">#{{ $ticket->id }}</td>
                            <td class="px-6 py-4">
                                <a href="{{ route('customer.tickets.show', $ticket) }}" class="font-medium text-brand-600 hover:underline">{{ Str::limit($ticket->title, 50) }}</a>
                            </td>
                            <td class="px-6 py-4 text-sm capitalize">{{ str_replace('_', ' ', $ticket->status) }}</td>
                            <td class="px-6 py-4 text-sm capitalize">{{ $ticket->priority }}</td>
                            <td class="px-6 py-4 text-sm">{{ $ticket->replies->count() }}</td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $ticket->created_at->format('M d, Y') }}</td>
                            <td class="px-6 py-4 text-sm">
                                <a href="{{ route('customer.tickets.show', $ticket) }}" class="text-brand-600 hover:underline">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-slate-500">
                                No tickets found. <a href="{{ route('customer.tickets.create') }}" class="text-brand-600 hover:underline">Create one</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($tickets->hasPages())
            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800">{{ $tickets->links() }}</div>
        @endif
    </div>
</div>
@endsection
