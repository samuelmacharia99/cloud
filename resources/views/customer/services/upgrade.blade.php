@extends('layouts.customer')

@section('title', 'Change Hosting Plan')

@section('content')
<div class="space-y-6 max-w-3xl">
    <div>
        <a href="{{ route('customer.services.show', $service) }}" class="text-sm text-brand-600 hover:underline">&larr; Back to service</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">Change hosting plan</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">
            Current plan: <strong>{{ $service->product->name }}</strong>
            · billed <span class="capitalize">{{ $billingCycle }}</span>
        </p>
    </div>

    @if (!empty($packageUsageInsight['needs_upgrade']))
        <div class="ui-card p-5 border-amber-200 dark:border-amber-800 bg-amber-50/80 dark:bg-amber-950/30">
            <p class="text-sm font-semibold text-amber-950 dark:text-amber-100">You're nearing your plan limits</p>
            <ul class="mt-2 space-y-1 text-sm text-amber-900 dark:text-amber-200">
                @if (!empty($packageUsageInsight['disk']['percent']) && !($packageUsageInsight['disk']['unlimited'] ?? false))
                    <li>Storage: {{ $packageUsageInsight['disk']['percent'] }}% used</li>
                @endif
                @if (!empty($packageUsageInsight['bandwidth']['percent']) && !($packageUsageInsight['bandwidth']['unlimited'] ?? false))
                    <li>Bandwidth: {{ $packageUsageInsight['bandwidth']['percent'] }}% used</li>
                @endif
                @if (!empty($packageUsageInsight['database']['percent']) && !($packageUsageInsight['database']['unlimited'] ?? false))
                    <li>Databases: {{ $packageUsageInsight['database']['percent'] }}% used</li>
                @endif
            </ul>
            @if ($recommendedOption)
                <p class="text-sm text-amber-900 dark:text-amber-200 mt-3">Recommended: <strong>{{ $recommendedOption['name'] }}</strong></p>
            @endif
        </div>
    @endif

    @if ($planOptions->isEmpty())
        <div class="ui-card p-8 text-center text-slate-600 dark:text-slate-400">
            <p>{{ $planChangeEmptyReason ?? 'No other shared hosting plans are available for this service right now.' }}</p>
        </div>
    @else
        <form
            method="POST"
            action="{{ route('customer.services.upgrade.store', $service) }}"
            class="space-y-4"
            id="plan-change-form"
            x-data="hostingPlanUpgradeForm(@js(old('billing_cycle', $billingCycle)))"
        >
            @csrf
            <input type="hidden" name="reseller_product_id" id="reseller_product_id" value="{{ old('reseller_product_id') }}">

            <div class="ui-card p-5">
                <label for="billing_cycle" class="block text-sm font-semibold text-slate-900 dark:text-white mb-2">
                    Billing cycle for the new plan
                </label>
                <select
                    id="billing_cycle"
                    name="billing_cycle"
                    x-model="cycle"
                    class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white"
                >
                    @foreach ($billingCycles as $cycleOption)
                        <option value="{{ $cycleOption }}" @selected(old('billing_cycle', $billingCycle) === $cycleOption)>
                            {{ ucfirst(str_replace('-', ' ', $cycleOption)) }}
                        </option>
                    @endforeach
                </select>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                    Upgrade charges are prorated for the rest of your current billing period. Your service will renew on the selected cycle after the next invoice.
                </p>
                @error('billing_cycle')
                    <p class="text-sm text-red-600 mt-2">{{ $message }}</p>
                @enderror
            </div>

            @foreach ($planOptions as $option)
                @php
                    $product = $option['product'];
                    $isRecommended = $recommendedOption
                        && (int) ($recommendedOption['reseller_product_id'] ?? 0) === (int) ($option['reseller_product_id'] ?? 0)
                        && (int) $recommendedOption['product']->id === (int) $product->id;
                    $changeType = $option['change_type'];
                    $badge = match ($changeType) {
                        'downgrade' => 'Downgrade',
                        'lateral' => 'Same tier',
                        default => 'Upgrade',
                    };
                    $cyclePrices = [];
                    foreach ($billingCycles as $cycleOption) {
                        $cyclePrices[$cycleOption] = $upgrades->displayPriceForPlanOption(auth()->user(), $option, $cycleOption);
                    }
                @endphp
                <label class="ui-card ui-card-interactive p-5 flex items-start gap-4 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-brand-500 {{ $isRecommended ? 'ring-2 ring-amber-400' : '' }}">
                    <input
                        type="radio"
                        name="product_id"
                        value="{{ $product->id }}"
                        class="mt-1 plan-option-radio"
                        required
                        data-reseller-product-id="{{ $option['reseller_product_id'] ?? '' }}"
                        data-cycle-prices='@json($cyclePrices)'
                        @checked(
                            (string) old('product_id', $recommendedOption['product']->id ?? '') === (string) $product->id
                            && (string) old('reseller_product_id', $recommendedOption['reseller_product_id'] ?? '') === (string) ($option['reseller_product_id'] ?? '')
                        )
                    >
                    <div class="flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-semibold text-slate-900 dark:text-white">{{ $option['name'] }}</p>
                            <span class="text-xs font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $badge }}</span>
                            @if ($isRecommended)
                                <span class="text-xs font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-200">Recommended</span>
                            @endif
                        </div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                            <span data-cycle-prices='@json($cyclePrices)' x-text="planPriceLabel($el)"></span>
                            @if($option['disk_quota'])
                                · {{ rtrim(rtrim(number_format($option['disk_quota'], 2), '0'), '.') }} GB storage
                            @endif
                            @if($option['bandwidth_quota'] !== null && (float) $option['bandwidth_quota'] >= 0)
                                · {{ rtrim(rtrim(number_format($option['bandwidth_quota'], 2), '0'), '.') }} GB bandwidth
                            @elseif($option['bandwidth_quota'] !== null)
                                · Unlimited bandwidth
                            @endif
                            @if(isset($option['num_databases']) && (int) $option['num_databases'] > 0)
                                · {{ $option['num_databases'] }} {{ \Illuminate\Support\Str::plural('database', (int) $option['num_databases']) }}
                            @endif
                        </p>
                        @if ($changeType === 'downgrade')
                            <p class="text-xs text-slate-500 mt-2">No charge for downgrades. Ensure usage fits the lower limits before switching.</p>
                        @endif
                    </div>
                </label>
            @endforeach

            @error('product_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <p class="text-sm text-slate-500">Upgrades are prorated for the rest of your current billing period. Downgrades apply at no extra cost after you confirm.</p>
            <button type="submit" class="btn-primary">Continue to billing</button>
        </form>
        <script>
            function hostingPlanUpgradeForm(initialCycle) {
                const cycleLabels = {
                    monthly: 'mo',
                    quarterly: 'qtr',
                    'semi-annual': '6 mo',
                    annual: 'yr',
                };

                return {
                    cycle: initialCycle,
                    planPriceLabel(el) {
                        const prices = JSON.parse(el.dataset.cyclePrices || '{}');
                        const amount = prices[this.cycle] ?? 0;
                        const suffix = cycleLabels[this.cycle] ?? 'mo';

                        return `KES ${Math.round(amount).toLocaleString()}/${suffix}`;
                    },
                };
            }

            const resellerProductInput = document.getElementById('reseller_product_id');
            const syncResellerProductId = () => {
                const selected = document.querySelector('.plan-option-radio:checked');
                resellerProductInput.value = selected?.dataset.resellerProductId || '';
            };
            document.querySelectorAll('.plan-option-radio').forEach((radio) => {
                radio.addEventListener('change', syncResellerProductId);
            });
            syncResellerProductId();
        </script>
    @endif
</div>
@endsection
