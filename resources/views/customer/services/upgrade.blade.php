@extends('layouts.customer')

@section('title', !empty($isContainerPlanChange) ? 'Change Application Hosting Plan' : 'Change Hosting Plan')

@section('content')
<div class="space-y-6 max-w-3xl">
    <div>
        <a href="{{ route('customer.services.show', $service) }}" class="text-sm text-brand-600 hover:underline">&larr; Back to service</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">
            {{ !empty($isContainerPlanChange) ? 'Change application hosting plan' : 'Change hosting plan' }}
        </h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">
            Current plan: <strong>{{ $service->product->name }}</strong>
            · billed <span class="capitalize">{{ $billingCycle }}</span>
            @if (!empty($isContainerPlanChange) && $service->product?->containerTemplate)
                · stack <span class="font-mono text-sm">{{ $service->product->containerTemplate->slug }}</span>
            @endif
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
            <p>{{ $planChangeEmptyReason ?? (!empty($isContainerPlanChange) ? 'No other application hosting plans are available for this stack right now.' : 'No other shared hosting plans are available for this service right now.') }}</p>
        </div>
    @else
        <form
            method="POST"
            action="{{ route('customer.services.upgrade.store', $service) }}"
            class="space-y-4"
            id="plan-change-form"
            x-data="hostingPlanUpgradeForm(@js(old('billing_cycle', $billingCycle)), @js($planEstimates))"
            x-init="syncSelectedPlan($refs.resellerProductId)"
        >
            @csrf
            <input type="hidden" name="reseller_product_id" id="reseller_product_id" x-ref="resellerProductId" value="{{ old('reseller_product_id') }}">

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
                    @if (!empty($isContainerPlanChange))
                        Upgrade charges are an estimated mid-cycle prorate of the plan difference. Downgrades and same-tier switches apply at no extra cost.
                    @else
                        Upgrade charges are prorated for the rest of your current billing period. Your service will renew on the selected cycle after the next invoice.
                    @endif
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
                    $planKey = $product->id . ':' . ($option['reseller_product_id'] ?? 0);
                    $cyclePrices = [];
                    foreach ($billingCycles as $cycleOption) {
                        if (! empty($isContainerPlanChange)) {
                            $factor = match ($cycleOption) {
                                'quarterly' => 3,
                                'semi-annual' => 6,
                                'annual' => 12,
                                default => 1,
                            };
                            $cyclePrices[$cycleOption] = round(((float) ($option['display_price'] ?? $product->price)) * $factor, 2);
                        } else {
                            $cyclePrices[$cycleOption] = $upgrades->displayPriceForPlanOption(auth()->user(), $option, $cycleOption);
                        }
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
                        data-plan-key="{{ $planKey }}"
                        data-cycle-prices='@json($cyclePrices)'
                        @change="syncSelectedPlan($refs.resellerProductId)"
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
                            @if (!empty($isContainerPlanChange))
                                · {{ $option['cpu'] ?? '—' }} CPU
                                · {{ number_format((int) ($option['memory_mb'] ?? 0)) }} MB RAM
                                · {{ rtrim(rtrim(number_format((float) ($option['disk_gb'] ?? 0), 2), '0'), '.') }} GB disk
                            @else
                                @if(!empty($option['disk_quota']))
                                    · {{ rtrim(rtrim(number_format($option['disk_quota'], 2), '0'), '.') }} GB storage
                                @endif
                                @if(array_key_exists('bandwidth_quota', $option) && $option['bandwidth_quota'] !== null && (float) $option['bandwidth_quota'] >= 0)
                                    · {{ rtrim(rtrim(number_format($option['bandwidth_quota'], 2), '0'), '.') }} GB bandwidth
                                @elseif(array_key_exists('bandwidth_quota', $option) && $option['bandwidth_quota'] !== null)
                                    · Unlimited bandwidth
                                @endif
                                @if(isset($option['num_databases']) && (int) $option['num_databases'] > 0)
                                    · {{ $option['num_databases'] }} {{ \Illuminate\Support\Str::plural('database', (int) $option['num_databases']) }}
                                @endif
                            @endif
                        </p>
                        @if ($changeType === 'downgrade')
                            <p class="text-xs text-slate-500 mt-2">No charge for downgrades. Ensure usage fits the lower limits before switching.</p>
                        @endif
                    </div>
                </label>
            @endforeach

            @error('product_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror

            <div
                x-show="selectedEstimate"
                x-cloak
                class="ui-card p-5 border-brand-200 dark:border-brand-800 bg-brand-50/60 dark:bg-brand-950/20"
            >
                <p class="text-sm font-semibold text-slate-900 dark:text-white">Due today</p>
                <p class="text-2xl font-bold text-brand-700 dark:text-brand-300 mt-1" x-text="formatMoney(selectedEstimate?.estimated_total ?? 0)"></p>
                <template x-if="selectedEstimate?.is_prorated && selectedEstimate?.days_remaining">
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-2" x-text="prorationExplanation(selectedEstimate)"></p>
                </template>
                <template x-if="selectedEstimate && !selectedEstimate.is_prorated && selectedEstimate.change_type !== 'downgrade'">
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-2">
                        One-time charge for the plan change.
                    </p>
                </template>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-3" x-show="selectedEstimate?.target_plan_price">
                    After this period, <span x-text="selectedEstimate?.target_plan_name"></span> renews at
                    <span x-text="formatMoney(selectedEstimate?.target_plan_price ?? 0)"></span>
                    <span x-text="'/' + cycleSuffix(cycle)"></span>.
                </p>
            </div>

            <p class="text-sm text-slate-500">
                @if (!empty($isContainerPlanChange))
                    Confirming applies new CPU/memory limits to your running app after payment (or immediately when free).
                @else
                    The amount due today is a prorated upgrade charge for the rest of your current billing period — not the full annual plan price. Downgrades apply at no extra cost after you confirm.
                @endif
            </p>
            <button type="submit" class="btn-primary">Continue to billing</button>
        </form>
        <script>
            function hostingPlanUpgradeForm(initialCycle, planEstimates) {
                const cycleLabels = {
                    monthly: 'mo',
                    quarterly: 'qtr',
                    'semi-annual': '6 mo',
                    annual: 'yr',
                };

                return {
                    cycle: initialCycle,
                    selectedPlanKey: null,
                    planEstimates: planEstimates || {},
                    get selectedEstimate() {
                        if (!this.selectedPlanKey) {
                            return null;
                        }

                        return this.planEstimates[this.selectedPlanKey]?.[this.cycle] ?? null;
                    },
                    cycleSuffix(cycle) {
                        return cycleLabels[cycle] ?? 'mo';
                    },
                    formatMoney(amount) {
                        return `KES ${Math.round(Number(amount) || 0).toLocaleString()}`;
                    },
                    prorationExplanation(estimate) {
                        const days = estimate.days_remaining ?? 0;
                        const from = estimate.current_plan_name ?? 'current plan';
                        const to = estimate.target_plan_name ?? 'new plan';
                        const dueDate = estimate.next_due_date
                            ? new Date(estimate.next_due_date + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
                            : null;

                        return `Prorated upgrade from ${from} to ${to} for ${days} day${days === 1 ? '' : 's'} remaining${dueDate ? ` until ${dueDate}` : ''}.`;
                    },
                    syncSelectedPlan(resellerInput) {
                        const selected = document.querySelector('.plan-option-radio:checked');
                        this.selectedPlanKey = selected?.dataset.planKey || null;
                        if (resellerInput) {
                            resellerInput.value = selected?.dataset.resellerProductId || '';
                        }
                    },
                    planPriceLabel(el) {
                        const prices = JSON.parse(el.dataset.cyclePrices || '{}');
                        const amount = prices[this.cycle] ?? 0;
                        const suffix = cycleLabels[this.cycle] ?? 'mo';

                        return `KES ${Math.round(amount).toLocaleString()}/${suffix}`;
                    },
                };
            }
        </script>
    @endif
</div>
@endsection
