@extends('layouts.customer')

@section('title', 'Deploy a service')

@section('content')
@php
    /** @var \Illuminate\Support\Collection<int, \App\Services\Customer\DeployTarget> $existingPlans */
    /** @var \Illuminate\Support\Collection<int, \App\Services\Customer\PlanOffer> $newPlans */
    $plansWithRoom = $existingPlans->filter(fn ($target) => $target->hasRoom);
@endphp

<div class="space-y-8" x-data="{ cycle: 'monthly' }">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-ink-500 dark:text-ink-400 mb-2">Deploy · Where it runs</p>
            <h1 class="font-display text-3xl sm:text-4xl text-ink-950 dark:text-white leading-[1.05]">
                @if($project)
                    Choose a plan for {{ $project->name }}
                @else
                    Where should this run?
                @endif
            </h1>
            <p class="text-ink-600 dark:text-ink-400 mt-2 max-w-xl text-[15px] leading-relaxed">
                Deploy on a plan you already have, or buy a new one. You pick the stack right after.
            </p>
        </div>
        <a href="{{ route('customer.cart.index') }}" class="shrink-0 inline-flex items-center gap-2 rounded-full border border-ink-200/80 dark:border-ink-700/80 bg-white/70 dark:bg-ink-900/60 backdrop-blur px-3.5 py-2 text-sm font-medium text-ink-700 dark:text-ink-200 hover:border-ink-300 dark:hover:border-ink-600 transition shadow-sm">
            Cart
            @if($cartCount > 0)
                <span class="min-w-[1.25rem] h-5 px-1.5 rounded-full bg-ink-950 dark:bg-brand-400 text-white dark:text-ink-950 text-[11px] font-bold flex items-center justify-center">{{ $cartCount }}</span>
            @endif
        </a>
    </div>

    @if(!empty($attachDomain))
        <div class="rounded-2xl border border-sky-200/80 dark:border-sky-800/60 bg-sky-50/80 dark:bg-sky-950/30 px-4 py-3.5 text-sm text-sky-950 dark:text-sky-100">
            <p class="font-semibold tracking-tight">Attaching {{ $attachDomain['fqdn'] }}</p>
            <p class="mt-0.5 text-sky-800/90 dark:text-sky-200/90">Domain stays in your cart — one checkout for domain + hosting.</p>
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-2xl border border-red-200 dark:border-red-800/60 bg-red-50 dark:bg-red-950/30 px-4 py-3 text-sm text-red-800 dark:text-red-200">{{ session('error') }}</div>
    @endif

    @if($existingPlans->isNotEmpty() && ! $project)
        <section data-deploy-section="existing">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="font-display text-lg font-bold text-ink-950 dark:text-white">Deploy on an existing plan</h2>
                <p class="text-xs text-ink-500 dark:text-ink-400">{{ $plansWithRoom->count() }} of {{ $existingPlans->count() }} with room · no extra charge</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($existingPlans as $target)
                    @include('customer.deploy.partials.existing-plan-card', ['target' => $target])
                @endforeach
            </div>
        </section>
    @endif

    <section data-deploy-section="new">
        <div class="mb-3 flex items-center justify-between gap-3">
            <h2 class="font-display text-lg font-bold text-ink-950 dark:text-white">Buy a new plan</h2>
            <label class="flex items-center gap-2 text-xs text-ink-600 dark:text-ink-300">
                Billing
                <select x-model="cycle" class="rounded-lg border border-ink-200 dark:border-ink-700 bg-white dark:bg-ink-900 px-2 py-1 text-xs">
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="semi-annual">Semi-annual</option>
                    <option value="annual">Annual</option>
                </select>
            </label>
        </div>

        @if($newPlans->isEmpty())
            <div class="ui-card rounded-xl border border-dashed border-ink-300/80 dark:border-ink-700/70 px-6 py-10 text-center">
                <p class="text-sm font-medium text-ink-700 dark:text-ink-200">No application hosting plans are on offer right now.</p>
                <p class="mt-1 text-xs text-ink-500 dark:text-ink-400">Contact support and we will sort one out.</p>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($newPlans as $offer)
                    @include('customer.deploy.partials.plan-card', ['offer' => $offer, 'project' => $project, 'currency' => $currency, 'currencyCode' => $currencyCode])
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection
