@php
    $credentials = is_string($service->credentials) ? json_decode($service->credentials, true) : ($service->credentials ?? []);
    $credUsername = $credentials['username'] ?? ($service->service_meta['username'] ?? '');
    $credPassword = $credentials['password'] ?? ($service->service_meta['password'] ?? '');
@endphp

<div class="flex items-center justify-end gap-0.5" x-data="{ menuOpen: false }">
    <a href="{{ route('admin.services.show', $service) }}"
        class="action-icon-btn text-brand-600 dark:text-brand-400 hover:bg-brand-50 dark:hover:bg-brand-950/40"
        title="View service">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
        </svg>
    </a>

    <button type="button"
        @click="openEdit({{ $service->id }}, @js($service->status->value), @js($service->billing_cycle), @js($service->next_due_date?->format('Y-m-d')), @js($service->commenced_at?->format('Y-m-d')), @js($credUsername), @js($credPassword))"
        class="action-icon-btn text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
        title="Quick edit">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
        </svg>
    </button>

    <div class="relative shrink-0">
        <button type="button" @click="menuOpen = !menuOpen"
            class="action-icon-btn text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
            title="More actions" aria-label="More actions">
            <svg fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/>
            </svg>
        </button>
        <div x-show="menuOpen" x-cloak @click.outside="menuOpen = false"
            class="absolute right-0 mt-1 w-48 bg-white dark:bg-slate-900 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 z-50 py-1 overflow-hidden">
            @if ($service->status->value === 'suspended')
                <form method="POST" action="{{ route('admin.services.unsuspend', $service) }}">
                    @csrf
                    <button type="submit" class="w-full text-left px-4 py-2.5 text-sm font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/40">
                        Unsuspend
                    </button>
                </form>
            @elseif (in_array($service->status->value, ['active', 'pending', 'provisioning', 'failed']))
                <form method="POST" action="{{ route('admin.services.suspend', $service) }}">
                    @csrf
                    <button type="submit" class="w-full text-left px-4 py-2.5 text-sm font-medium text-orange-700 dark:text-orange-300 hover:bg-orange-50 dark:hover:bg-orange-950/40">
                        Suspend
                    </button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.services.destroy', $service) }}" data-confirm="Delete service #{{ $service->id }}? This cannot be undone.">
                @csrf
                @method('DELETE')
                <button type="submit" class="w-full text-left px-4 py-2.5 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40">
                    Delete
                </button>
            </form>
        </div>
    </div>
</div>
