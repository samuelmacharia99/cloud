@extends('layouts.app')

@section('title', 'Support Tickets')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Support Tickets</h1>
                    <p class="mt-2 text-gray-600 dark:text-gray-400">View and manage your support tickets</p>
                </div>
                <a href="{{ route('customer.tickets.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-2xl font-medium transition-colors">
                    Create Ticket
                </a>
            </div>

            <!-- Tickets Table -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900 dark:text-white">ID</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900 dark:text-white">Title</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900 dark:text-white">Status</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900 dark:text-white">Priority</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900 dark:text-white">Replies</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900 dark:text-white">Created</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900 dark:text-white">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            @forelse($query as $ticket)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">#{{ $ticket->id }}</td>
                                <td class="px-6 py-4">
                                    <a href="{{ route('customer.tickets.show', $ticket) }}" class="text-blue-600 hover:text-blue-700 font-medium">
                                        {{ Str::limit($ticket->title, 40) }}
                                    </a>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold
                                        @if($ticket->status === 'open') bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200
                                        @elseif($ticket->status === 'in_progress') bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200
                                        @elseif($ticket->status === 'on_hold') bg-purple-100 dark:bg-purple-900 text-purple-800 dark:text-purple-200
                                        @else bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200
                                        @endif">
                                        {{ ucfirst(str_replace('_', ' ', $ticket->status)) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold
                                        @if($ticket->priority === 'urgent') bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200
                                        @elseif($ticket->priority === 'high') bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200
                                        @elseif($ticket->priority === 'medium') bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200
                                        @else bg-slate-100 dark:bg-slate-700 text-slate-800 dark:text-slate-200
                                        @endif">
                                        {{ ucfirst($ticket->priority) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                                    {{ $ticket->replies->count() }}
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                                    {{ $ticket->created_at->format('M d, Y') }}
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <a href="{{ route('customer.tickets.show', $ticket) }}" class="text-blue-600 hover:text-blue-700 font-medium">
                                        View
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-gray-600 dark:text-gray-400">
                                    No tickets found. <a href="{{ route('customer.tickets.create') }}" class="text-blue-600 hover:text-blue-700 font-medium">Create one now</a>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-700">
                    {{ $query->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
