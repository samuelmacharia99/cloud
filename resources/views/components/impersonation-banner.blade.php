{{--
    One banner for every portal.

    The admin, reseller and customer layouts each used to carry their own copy,
    and they disagreed about which session flag won when an admin was viewing a
    reseller who was viewing a customer. The trail now decides: the frame on top
    names the account this session came from, so a nested view always offers the
    step back that was actually taken.
--}}
@php
    $impersonation = app(\App\Services\ImpersonationService::class);
    // A trail without a logged-in user should be impossible, but a banner is on
    // every page of three portals and must never be the thing that 500s one.
    $frame = auth()->check() ? $impersonation->currentFrame() : null;
@endphp

@if ($frame)
    @php
        $actorIsAdmin = ($frame['actor_role'] ?? null) === \App\Services\ImpersonationService::ROLE_ADMIN;
        $actorName = $frame['actor_name'] ?? 'your account';
        $depth = $impersonation->depth();

        $tone = $actorIsAdmin
            ? 'bg-amber-50 dark:bg-amber-950 border-amber-200 dark:border-amber-800'
            : 'bg-cyan-50 dark:bg-cyan-950 border-cyan-200 dark:border-cyan-800';
        $icon = $actorIsAdmin ? 'text-amber-600 dark:text-amber-400' : 'text-cyan-600 dark:text-cyan-400';
        $text = $actorIsAdmin ? 'text-amber-900 dark:text-amber-100' : 'text-cyan-900 dark:text-cyan-100';
        $muted = $actorIsAdmin ? 'text-amber-700 dark:text-amber-300' : 'text-cyan-700 dark:text-cyan-300';
        $button = $actorIsAdmin
            ? 'bg-amber-600 hover:bg-amber-700 dark:bg-amber-700 dark:hover:bg-amber-600'
            : 'bg-cyan-600 hover:bg-cyan-700 dark:bg-cyan-700 dark:hover:bg-cyan-600';
    @endphp

    <div class="min-h-12 py-3 sm:py-0 sm:h-12 {{ $tone }} border-b flex flex-col gap-3 sm:flex-row sm:items-center px-4 sm:px-6 sm:justify-between">
        <div class="flex items-center gap-2 min-w-0">
            <svg class="w-5 h-5 shrink-0 {{ $icon }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                @if ($actorIsAdmin)
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12a9 9 0 11-18 0 9 9 0 0118 0m-.5 4.5h.01"/>
                @else
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                @endif
            </svg>
            <span class="text-sm font-medium truncate {{ $text }}">
                You are viewing as <strong>{{ auth()->user()->name }}</strong>.
                @if ($depth > 1)
                    <span class="{{ $muted }}">Signed in through {{ $depth }} accounts.</span>
                @endif
            </span>
        </div>

        <form method="POST" action="{{ route('exit-impersonation') }}" class="flex items-center gap-2 shrink-0">
            @csrf
            <button type="submit" class="px-4 py-1.5 {{ $button }} text-white text-sm font-medium rounded-lg transition">
                Back to {{ $actorName }}
            </button>
        </form>
    </div>
@endif
