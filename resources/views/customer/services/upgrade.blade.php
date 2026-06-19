@extends('layouts.customer')

@section('title', 'Upgrade Plan')

@section('content')
<div class="space-y-6 max-w-3xl">
    <div>
        <a href="{{ route('customer.services.show', $service) }}" class="text-sm text-brand-600 hover:underline">&larr; Back to service</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">Upgrade hosting plan</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Current plan: <strong>{{ $service->product->name }}</strong></p>
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
            @if ($recommendedUpgrade)
                <p class="text-sm text-amber-900 dark:text-amber-200 mt-3">Recommended next plan: <strong>{{ $recommendedUpgrade->name }}</strong></p>
            @endif
        </div>
    @endif

    @if ($upgradeOptions->isEmpty())
        <div class="ui-card p-8 text-center text-slate-600 dark:text-slate-400">
            No higher plans are available for this service right now.
        </div>
    @else
        <form method="POST" action="{{ route('customer.services.upgrade.store', $service) }}" class="space-y-4">
            @csrf
            @foreach ($upgradeOptions as $product)
                @php
                    $package = $product->directAdminPackage;
                    $isRecommended = $recommendedUpgrade && $recommendedUpgrade->id === $product->id;
                @endphp
                <label class="ui-card ui-card-interactive p-5 flex items-start gap-4 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-brand-500 {{ $isRecommended ? 'ring-2 ring-amber-400' : '' }}">
                    <input type="radio" name="product_id" value="{{ $product->id }}" class="mt-1" required @checked(old('product_id', $recommendedUpgrade?->id) == $product->id)>
                    <div class="flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-semibold text-slate-900 dark:text-white">{{ $product->name }}</p>
                            @if ($isRecommended)
                                <span class="text-xs font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-200">Recommended</span>
                            @endif
                        </div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                            KES {{ number_format($product->monthly_price, 0) }}/mo
                            @if($package)
                                · {{ rtrim(rtrim(number_format($package->disk_quota, 2), '0'), '.') }} GB storage
                                · {{ $package->bandwidth_quota && $package->bandwidth_quota >= 0 ? rtrim(rtrim(number_format($package->bandwidth_quota, 2), '0'), '.').' GB bandwidth' : 'Unlimited bandwidth' }}
                                · {{ $package->num_databases }} {{ \Illuminate\Support\Str::plural('database', $package->num_databases) }}
                            @endif
                        </p>
                    </div>
                </label>
            @endforeach
            @error('product_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <p class="text-sm text-slate-500">A prorated invoice will be created for the remaining billing period. After payment, your new limits are applied automatically.</p>
            <button type="submit" class="btn-primary">Create upgrade invoice</button>
        </form>
    @endif
</div>
@endsection
