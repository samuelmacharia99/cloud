@extends('layouts.customer')

@section('title', 'Notifications')

@section('content')
<div class="space-y-6">
    <x-page-header title="Notifications" description="In-app updates for invoices, services, domains, and support.">
        <x-slot:actions>
            @if($unreadCount > 0)
                <form method="POST" action="{{ route('customer.notifications.read-all') }}">
                    @csrf
                    <button class="btn-secondary">Mark all read</button>
                </form>
            @endif
            <a href="{{ route('profile.notifications') }}" class="btn-secondary">Email / SMS prefs</a>
        </x-slot:actions>
    </x-page-header>

    <div class="ui-card divide-y divide-slate-100 dark:divide-slate-800">
        @forelse($notifications as $item)
            <form method="POST" action="{{ route('customer.notifications.read', $item) }}" class="block">
                @csrf
                <button type="submit" class="w-full text-left px-5 py-4 hover:bg-slate-50 dark:hover:bg-slate-800/50 {{ $item->isUnread() ? 'bg-teal-50/50 dark:bg-teal-950/20' : '' }}">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $item->title }}</p>
                            @if($item->body)
                                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">{{ $item->body }}</p>
                            @endif
                            <p class="text-xs text-slate-400 mt-2">{{ $item->created_at?->format('M j, Y g:i A') }} · {{ str_replace('_', ' ', $item->type) }}</p>
                        </div>
                        @if($item->isUnread())
                            <span class="shrink-0 mt-1 h-2 w-2 rounded-full bg-teal-500"></span>
                        @endif
                    </div>
                </button>
            </form>
        @empty
            <div class="px-6 py-12 text-center text-sm text-slate-500">No notifications yet.</div>
        @endforelse
    </div>

    <div>{{ $notifications->links() }}</div>
</div>
@endsection
