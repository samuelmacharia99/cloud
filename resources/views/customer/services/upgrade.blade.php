@extends('layouts.customer')

@section('title', 'Upgrade Plan')

@section('content')
<div class="space-y-6 max-w-3xl">
    <div>
        <a href="{{ route('customer.services.show', $service) }}" class="text-sm text-brand-600 hover:underline">&larr; Back to service</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">Upgrade hosting plan</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Current plan: <strong>{{ $service->product->name }}</strong></p>
    </div>

    @if ($upgradeOptions->isEmpty())
        <div class="ui-card p-8 text-center text-slate-600 dark:text-slate-400">
            No higher plans are available for this service right now.
        </div>
    @else
        <form method="POST" action="{{ route('customer.services.upgrade.store', $service) }}" class="space-y-4">
            @csrf
            @foreach ($upgradeOptions as $product)
                <label class="ui-card ui-card-interactive p-5 flex items-start gap-4 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-brand-500">
                    <input type="radio" name="product_id" value="{{ $product->id }}" class="mt-1" required @checked(old('product_id') == $product->id)>
                    <div class="flex-1">
                        <p class="font-semibold text-slate-900 dark:text-white">{{ $product->name }}</p>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                            KES {{ number_format($product->monthly_price, 0) }}/mo
                            @if($product->directAdminPackage)
                                · {{ $product->directAdminPackage->disk_quota }} MB disk
                            @endif
                        </p>
                    </div>
                </label>
            @endforeach
            @error('product_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <p class="text-sm text-slate-500">A prorated invoice will be created for the remaining billing period.</p>
            <button type="submit" class="btn-primary">Create upgrade invoice</button>
        </form>
    @endif
</div>
@endsection
