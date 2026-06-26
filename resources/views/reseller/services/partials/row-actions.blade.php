@php
    $status = $service->status->value ?? $service->status;
    $canSuspend = in_array($status, ['active', 'pending', 'provisioning', 'failed'], true);
    $canUnsuspend = $status === 'suspended';
@endphp

<div class="inline-flex items-center justify-end gap-3 flex-wrap">
    <a href="{{ route('reseller.services.show', $service) }}" class="text-purple-600 dark:text-purple-400 text-sm font-medium hover:underline">View</a>

    @if ($canUnsuspend)
        <form method="POST" action="{{ route('reseller.services.unsuspend', $service) }}" class="inline">
            @csrf
            <button type="submit" class="text-emerald-600 dark:text-emerald-400 text-sm font-medium hover:underline">Unsuspend</button>
        </form>
    @elseif ($canSuspend)
        <form
            method="POST"
            action="{{ route('reseller.services.suspend', $service) }}"
            class="inline"
            data-confirm="Suspend this service? The customer may lose access until you unsuspend it."
        >
            @csrf
            <button type="submit" class="text-amber-600 dark:text-amber-400 text-sm font-medium hover:underline">Suspend</button>
        </form>
    @endif
</div>
