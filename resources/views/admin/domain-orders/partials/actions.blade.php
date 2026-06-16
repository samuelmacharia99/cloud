@php
    $filterQuery = request()->only(['search', 'status', 'reseller_id', 'from_date', 'to_date', 'page']);
@endphp

<div class="inline-flex items-center justify-end gap-0.5">
    <a
        href="{{ route('admin.domain-orders.show', $order) }}"
        class="action-icon-btn text-brand-600 dark:text-brand-400 hover:bg-brand-50 dark:hover:bg-brand-950/40"
        title="View order details"
    >
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
        </svg>
    </a>

    @if ($order->canAdminPush())
        <form
            method="POST"
            action="{{ route('admin.domain-orders.push', $order) }}"
            class="inline"
            data-confirm="{{ $order->adminPrepareConfirmMessage() }}"
        >
            @csrf
            @foreach ($filterQuery as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <button
                type="submit"
                class="action-icon-btn text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-950/40"
                title="{{ $order->adminPrepareButtonTitle() }}"
            >
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
            </button>
        </form>
    @endif

    @if ($order->canAdminPushToRegistrar())
        <form
            method="POST"
            action="{{ route('admin.domain-orders.push-registrar', $order) }}"
            class="inline"
            data-confirm="Submit {{ $order->fullDomainName() }} to the API registrar now?@if($order->status === 'failed') This will retry a failed submission.@endif Ensure Openprovider balance and contact handles are configured."
        >
            @csrf
            @foreach ($filterQuery as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <button
                type="submit"
                class="action-icon-btn text-violet-600 dark:text-violet-400 hover:bg-violet-50 dark:hover:bg-violet-950/40"
                title="Push to registrar API (Openprovider) — manual register/transfer or retry"
            >
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                </svg>
            </button>
        </form>
    @endif

    @if ($order->canAdminComplete())
        <button
            type="button"
            @click="$dispatch('open-domain-order-complete', { orderId: {{ $order->id }}, domain: @js($order->fullDomainName()) })"
            class="action-icon-btn text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40"
            title="{{ match($order->status) {
                'queued' => 'Mark as completed (wholesale paid — manual registration)',
                'failed' => 'Mark as completed (manual registration — overrides failed API attempt)',
                default => 'Mark as completed (manual registration — no API registrar)',
            } }}"
        >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </button>
        @if ($order->status === 'pushed')
        <button
            type="button"
            @click="$dispatch('open-domain-order-fail', { orderId: {{ $order->id }}, domain: @js($order->fullDomainName()) })"
            class="action-icon-btn text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40"
            title="Mark as failed (pushed — registration could not be completed)"
        >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </button>
        @endif
    @endif

    @if ($order->canCancel())
        <form
            method="POST"
            action="{{ route('admin.domain-orders.cancel', $order) }}"
            class="inline"
            data-confirm="Cancel domain order {{ $order->fullDomainName() }}? Pending registration will not proceed."
        >
            @csrf
            @foreach ($filterQuery as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <button
                type="submit"
                class="action-icon-btn text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
                title="Cancel order"
            >
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                </svg>
            </button>
        </form>
    @endif

    @if ($order->canAdminDelete())
        <form
            method="POST"
            action="{{ route('admin.domain-orders.destroy', $order) }}"
            class="inline"
            data-confirm="Delete domain order {{ $order->fullDomainName() }}? This cannot be undone."
        >
            @csrf
            @method('DELETE')
            @foreach ($filterQuery as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <button
                type="submit"
                class="action-icon-btn text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40"
                title="Delete order record"
            >
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </button>
        </form>
    @endif
</div>
