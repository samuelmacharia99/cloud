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
            data-confirm="Push {{ $order->fullDomainName() }} to admin for registration?@if($order->isPlatformOrder()) Customer payment is on file.@elseif($order->hasPaidWholesaleInvoice()) Wholesale invoice is already paid — no wallet debit.@else Uses reseller wallet if no paid invoice is on file.@endif"
        >
            @csrf
            @foreach ($filterQuery as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <button
                type="submit"
                class="action-icon-btn text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-950/40"
                title="@if($order->isPlatformOrder()) Push to admin (customer paid) @elseif($order->hasPaidWholesaleInvoice()) Push to admin (wholesale invoice paid — no wallet debit) @else Push to admin (uses wallet if wholesale invoice not paid) @endif"
            >
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
            </button>
        </form>
    @endif

    @if ($order->canAdminComplete())
        <button
            type="button"
            @click="$dispatch('open-domain-order-complete', { orderId: {{ $order->id }}, domain: @js($order->fullDomainName()) })"
            class="action-icon-btn text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40"
            title="{{ $order->status === 'queued' ? 'Mark as completed (wholesale paid — will push then register)' : 'Mark as completed (domain registered at registrar)' }}"
        >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </button>
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
