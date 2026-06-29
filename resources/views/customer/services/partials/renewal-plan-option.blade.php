@php
    $product = $option['product'];
    $displayPrice = $option['display_price'];
@endphp

<label class="ui-card ui-card-interactive p-5 flex items-start gap-4 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-brand-500">
    <input
        type="radio"
        name="product_id"
        value="{{ $product->id }}"
        class="mt-1 renewal-plan-radio"
        required
        data-reseller-product-id="{{ $option['reseller_product_id'] ?? '' }}"
        @checked($checked)
    >
    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
            <p class="font-semibold text-slate-900 dark:text-white">{{ $option['name'] }}</p>
            <span class="text-xs font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full {{ $badgeClass }}">{{ $badge }}</span>
        </div>
        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
            KES {{ number_format($displayPrice, 0) }}/{{ $cycleLabel }}
            @if (! empty($option['disk_quota']))
                · {{ rtrim(rtrim(number_format($option['disk_quota'], 2), '0'), '.') }} GB storage
            @endif
            @if (isset($option['bandwidth_quota']) && $option['bandwidth_quota'] !== null && (float) $option['bandwidth_quota'] >= 0)
                · {{ rtrim(rtrim(number_format($option['bandwidth_quota'], 2), '0'), '.') }} GB bandwidth
            @elseif (isset($option['bandwidth_quota']) && $option['bandwidth_quota'] !== null)
                · Unlimited bandwidth
            @endif
            @if (! empty($option['num_databases']))
                · {{ $option['num_databases'] }} {{ \Illuminate\Support\Str::plural('database', $option['num_databases']) }}
            @endif
        </p>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">{{ $description }}</p>
    </div>
</label>
