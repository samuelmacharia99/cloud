@php
    $hasActions = $order->status === 'pushed'
        || $order->canCancel()
        || $order->canDelete();
    $filterQuery = request()->only(['search', 'status', 'reseller_id', 'from_date', 'to_date', 'page']);
@endphp

@if ($hasActions)
    <div class="inline-flex flex-wrap items-center justify-end gap-1.5">
        @if ($order->status === 'pushed')
            <button
                type="button"
                @click="$dispatch('open-domain-order-complete', { orderId: {{ $order->id }}, domain: @js($order->fullDomainName()) })"
                class="px-2.5 py-1 text-xs font-semibold rounded-lg text-white bg-emerald-600 hover:bg-emerald-700 transition whitespace-nowrap"
            >
                Complete
            </button>
            <button
                type="button"
                @click="$dispatch('open-domain-order-fail', { orderId: {{ $order->id }}, domain: @js($order->fullDomainName()) })"
                class="px-2.5 py-1 text-xs font-semibold rounded-lg text-red-700 dark:text-red-300 bg-red-50 hover:bg-red-100 dark:bg-red-950/40 dark:hover:bg-red-950/60 transition whitespace-nowrap"
            >
                Fail
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
                <button type="submit" class="px-2.5 py-1 text-xs font-semibold rounded-lg text-slate-700 dark:text-slate-200 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 transition whitespace-nowrap">
                    Cancel
                </button>
            </form>
        @endif

        @if ($order->canDelete())
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
                <button type="submit" class="px-2.5 py-1 text-xs font-semibold rounded-lg text-red-700 dark:text-red-300 bg-red-50 hover:bg-red-100 dark:bg-red-950/40 dark:hover:bg-red-950/60 transition whitespace-nowrap">
                    Delete
                </button>
            </form>
        @endif
    </div>
@else
    <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
@endif
