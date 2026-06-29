@extends('layouts.customer')

@section('title', 'Renew Service')

@section('content')
@php
    $current = $renewalOptions['current'];
    $upgrades = $renewalOptions['upgrades'];
    $billingCycle = $renewalOptions['billing_cycle'];
    $cycleLabel = $billingCycle === 'annual' ? 'yr' : 'mo';
    $allOptions = collect([$current])->merge($upgrades);
@endphp

<div class="space-y-6 max-w-3xl">
    <div>
        <a href="{{ route('customer.services.index') }}" class="text-sm text-brand-600 hover:underline">&larr; Back to services</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">Renew your service</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">
            @if ($renewalOptions['can_choose_plan'])
                Choose whether to keep your current plan or upgrade to a higher package before generating your renewal invoice.
            @else
                Confirm your renewal package and continue to payment.
            @endif
        </p>
    </div>

    <div class="ui-card p-5">
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div>
                <dt class="text-slate-500 dark:text-slate-400">Service</dt>
                <dd class="font-semibold text-slate-900 dark:text-white mt-0.5">{{ $current['name'] }}</dd>
            </div>
            <div>
                <dt class="text-slate-500 dark:text-slate-400">Billing cycle</dt>
                <dd class="font-semibold text-slate-900 dark:text-white mt-0.5 capitalize">{{ $billingCycle }}</dd>
            </div>
            <div>
                <dt class="text-slate-500 dark:text-slate-400">Next due</dt>
                <dd class="font-semibold text-slate-900 dark:text-white mt-0.5">{{ $service->next_due_date?->format('M d, Y') ?? '—' }}</dd>
            </div>
        </dl>
    </div>

    <form method="POST" action="{{ route('customer.services.renew.store', $service) }}" class="space-y-6" id="renewal-form">
        @csrf
        <input type="hidden" name="reseller_product_id" id="reseller_product_id" value="{{ old('reseller_product_id') }}">

        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Keep current plan</h2>
            @include('customer.services.partials.renewal-plan-option', [
                'option' => $current,
                'billingCycle' => $billingCycle,
                'cycleLabel' => $cycleLabel,
                'badge' => 'Current plan',
                'badgeClass' => 'bg-brand-100 text-brand-800 dark:bg-brand-950/60 dark:text-brand-200',
                'description' => 'Renew at your existing package and price.',
                'checked' => (string) old('product_id', $current['product']->id) === (string) $current['product']->id
                    && (string) old('reseller_product_id', $current['reseller_product_id'] ?? '') === (string) ($current['reseller_product_id'] ?? ''),
            ])
        </section>

        @if ($upgrades->isNotEmpty())
            <section class="space-y-3">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Upgrade while renewing</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400">
                    Switch to a higher plan in the same category for your next billing period. Your account will be upgraded when payment is received.
                </p>

                @foreach ($upgrades as $option)
                    @php
                        $badge = match ($option['change_type']) {
                            'lateral' => 'Alternative plan',
                            default => 'Upgrade',
                        };
                        $isChecked = (string) old('product_id') === (string) $option['product']->id
                            && (string) old('reseller_product_id', '') === (string) ($option['reseller_product_id'] ?? '');
                    @endphp
                    @include('customer.services.partials.renewal-plan-option', [
                        'option' => $option,
                        'billingCycle' => $billingCycle,
                        'cycleLabel' => $cycleLabel,
                        'badge' => $badge,
                        'badgeClass' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200',
                        'description' => 'Renew on the '.$option['name'].' package instead.',
                        'checked' => $isChecked,
                    ])
                @endforeach
            </section>
        @endif

        @error('product_id')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        <div class="flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Payment extends your service from the current next due date.
            </p>
            <button type="submit" class="btn-primary">Continue to payment</button>
        </div>
    </form>
</div>

<script>
    const resellerProductInput = document.getElementById('reseller_product_id');
    const syncResellerProductId = () => {
        const selected = document.querySelector('.renewal-plan-radio:checked');
        resellerProductInput.value = selected?.dataset.resellerProductId || '';
    };
    document.querySelectorAll('.renewal-plan-radio').forEach((radio) => {
        radio.addEventListener('change', syncResellerProductId);
    });
    syncResellerProductId();
</script>
@endsection
