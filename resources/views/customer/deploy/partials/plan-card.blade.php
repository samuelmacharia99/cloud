@php
    /** @var \App\Services\Customer\PlanOffer $offer */
    $limits = $offer->resourceLimits;
    $fmt = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $memoryGb = isset($limits['memory']) && $limits['memory'] !== '' ? $fmt(((float) $limits['memory']) / 1024).' GB' : null;
    $monthly = $offer->monthlyPrice;
    $yearly = $offer->yearlyPrice ?? $monthly * 12;
@endphp
<article class="ui-card flex flex-col rounded-2xl p-5 {{ $offer->featured ? 'ring-1 ring-brand-400/60' : '' }}" data-plan-offer="{{ $offer->key() }}">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 class="truncate text-base font-semibold text-ink-950 dark:text-white">{{ $offer->name }}</h3>
            @if($offer->description)
                <p class="mt-0.5 text-xs text-ink-500 dark:text-ink-400 line-clamp-2">{{ $offer->description }}</p>
            @endif
        </div>
        @if($offer->featured)
            <span class="status-pill bg-brand-100 dark:bg-brand-950/60 text-brand-700 dark:text-brand-200">Popular</span>
        @endif
    </div>

    <p class="mt-3 text-2xl font-bold text-ink-950 dark:text-white">
        <span x-show="cycle === 'monthly'">{{ $currency->symbol ?? $currencyCode }} {{ number_format($monthly, 2) }}<span class="text-sm font-normal text-ink-500"> / month</span></span>
        <span x-show="cycle === 'quarterly'" x-cloak>{{ $currency->symbol ?? $currencyCode }} {{ number_format($monthly * 3, 2) }}<span class="text-sm font-normal text-ink-500"> / quarter</span></span>
        <span x-show="cycle === 'semi-annual'" x-cloak>{{ $currency->symbol ?? $currencyCode }} {{ number_format($monthly * 6, 2) }}<span class="text-sm font-normal text-ink-500"> / 6 months</span></span>
        <span x-show="cycle === 'annual'" x-cloak>{{ $currency->symbol ?? $currencyCode }} {{ number_format($yearly, 2) }}<span class="text-sm font-normal text-ink-500"> / year</span></span>
    </p>

    <dl class="mt-3 grid grid-cols-3 gap-2 text-xs">
        <div class="rounded-xl bg-ink-50/80 dark:bg-white/5 px-2.5 py-2"><dt class="text-ink-500 dark:text-ink-400">CPU</dt><dd class="font-semibold text-ink-900 dark:text-white">{{ isset($limits['cpu']) ? $fmt($limits['cpu']).' '.Str::plural('core', (float) $limits['cpu']) : '—' }}</dd></div>
        <div class="rounded-xl bg-ink-50/80 dark:bg-white/5 px-2.5 py-2"><dt class="text-ink-500 dark:text-ink-400">RAM</dt><dd class="font-semibold text-ink-900 dark:text-white">{{ $memoryGb ?? '—' }}</dd></div>
        <div class="rounded-xl bg-ink-50/80 dark:bg-white/5 px-2.5 py-2"><dt class="text-ink-500 dark:text-ink-400">Disk</dt><dd class="font-semibold text-ink-900 dark:text-white">{{ isset($limits['disk']) ? $fmt($limits['disk']).' GB' : '—' }}</dd></div>
    </dl>

    @if($offer->features !== [])
        <ul class="mt-3 space-y-1 text-xs text-ink-600 dark:text-ink-300">
            @foreach(array_slice($offer->features, 0, 4) as $feature)
                <li class="flex items-start gap-1.5"><span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500"></span><span>{{ $feature }}</span></li>
            @endforeach
        </ul>
    @endif

    <p class="mt-3 text-xs text-ink-500 dark:text-ink-400">
        @if($offer->pinnedTemplateName)
            Runs {{ $offer->pinnedTemplateName }} only.
        @else
            Runs {{ $offer->eligibleStackCount }} of the available {{ Str::plural('stack', $offer->eligibleStackCount) }}.
        @endif
    </p>

    <form method="POST" action="{{ route('customer.deploy-service.plan') }}" class="mt-4">
        @csrf
        @if($offer->resellerProductId)
            <input type="hidden" name="reseller_product_id" value="{{ $offer->resellerProductId }}">
        @else
            <input type="hidden" name="product_id" value="{{ $offer->productId }}">
        @endif
        <input type="hidden" name="billing_cycle" :value="cycle" value="monthly">
        @if($project)
            <input type="hidden" name="project_id" value="{{ $project->id }}">
        @endif
        <button type="submit" class="btn-primary btn-sm w-full">Choose plan &amp; pick a stack</button>
    </form>
</article>
