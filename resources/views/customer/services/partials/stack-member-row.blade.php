@php
    /** @var \App\Services\Provisioning\StackMember $member */
    /** @var \App\Services\Customer\StackFolder $folder */
    $memberService = $folder->services->first(fn ($s) => (int) $s->id === $member->serviceId) ?? $folder->anchor;
    $restartable = $member->canRestartAlone() && $folder->isDeployed();
    $icon = match ($member->kind->icon()) {
        'database' => 'M4 7c0-1.657 3.582-3 8-3s8 1.343 8 3-3.582 3-8 3-8-1.343-8-3zm0 5c0 1.657 3.582 3 8 3s8-1.343 8-3M4 7v10c0 1.657 3.582 3 8 3s8-1.343 8-3V7',
        'window' => 'M4 5h16a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V6a1 1 0 011-1zm0 4h17',
        'globe' => 'M12 21a9 9 0 100-18 9 9 0 000 18zm0-18c2.5 2.5 3.75 5.5 3.75 9S14.5 18.5 12 21m0-18C9.5 5.5 8.25 8.5 8.25 12S9.5 18.5 12 21M3 12h18',
        'bolt' => 'M13 3L4 14h7l-1 7 9-11h-7l1-7z',
        'cog' => 'M12 15a3 3 0 100-6 3 3 0 000 6zm7-3a7 7 0 01-.1 1.2l2 1.6-2 3.4-2.4-1a7 7 0 01-2 1.2l-.4 2.6h-4l-.4-2.6a7 7 0 01-2-1.2l-2.4 1-2-3.4 2-1.6A7 7 0 015 12a7 7 0 01.1-1.2l-2-1.6 2-3.4 2.4 1a7 7 0 012-1.2L9.9 3h4l.4 2.6a7 7 0 012 1.2l2.4-1 2 3.4-2 1.6c.1.4.1.8.1 1.2z',
        'cube' => 'M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3zm0 0v18M4 7.5l8 4.5 8-4.5',
        default => 'M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2',
    };
@endphp

<li class="flex items-center gap-3 px-4 py-2.5" data-stack-member="{{ $member->composeKey }}" data-stack-member-kind="{{ $member->kind->value }}">
    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-ink-100/80 dark:bg-white/10 text-ink-600 dark:text-ink-200" aria-hidden="true">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $icon }}"/></svg>
    </span>

    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
            <span class="text-sm font-semibold text-ink-900 dark:text-white">{{ $member->label }}</span>
            @if($member->databaseTypeLabel())
                <span class="rounded-md bg-ink-100/80 dark:bg-white/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-600 dark:text-ink-300">{{ $member->databaseTypeLabel() }}</span>
            @endif
            @if($folder->isSplitAcrossServices())
                <span class="text-[11px] text-ink-400 dark:text-ink-500">{{ $memberService->customerServiceName() }}</span>
            @endif
        </div>
        <p class="truncate font-mono text-[11px] text-ink-500 dark:text-ink-400">
            {{ $member->composeKey }}<span class="text-ink-300 dark:text-ink-600"> · </span>{{ $member->containerName }}
        </p>
    </div>

    <div class="flex shrink-0 items-center gap-2">
        <div class="text-right">
            <x-container-state-pill :state="$member->state" compact />
            @if($member->state->checkedAt)
                <p class="mt-0.5 text-[10px] text-ink-400 dark:text-ink-500">checked {{ $member->state->checkedAt->diffForHumans(short: true) }}</p>
            @endif
        </div>

        @if($restartable)
            <form method="POST" action="{{ route('customer.services.container.members.restart', [$memberService, $member->composeKey]) }}"
                  data-confirm="Restart the {{ strtolower($member->label) }} container? The app will lose its {{ strtolower($member->label) }} connection for a few seconds.">
                @csrf
                <button type="submit" class="btn-secondary btn-sm" title="Restart only the {{ strtolower($member->label) }} container">Restart</button>
            </form>
        @elseif($member->kind->isApplication() && ! $member->synthesized)
            <span class="text-[11px] text-ink-400 dark:text-ink-500" title="Application containers restart with the stack">stack action</span>
        @endif
    </div>
</li>
