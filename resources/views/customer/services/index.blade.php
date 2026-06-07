@extends('layouts.customer')

@section('title', 'My Services')

@section('content')
<div class="space-y-6">
    <x-page-header title="My Services" description="Manage your active subscriptions, hosting, and containers.">
        <x-slot:actions>
            <a href="{{ route('customer.select-techstack') }}" class="btn-primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Deploy new service
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($services->count() > 0)
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-6">
            @foreach ($services as $service)
                @php $canRenew = in_array($service->status->value, ['active', 'suspended']); @endphp
                <article class="ui-card ui-card-interactive flex flex-col overflow-hidden group">
                    <a href="{{ route('customer.services.show', $service) }}" class="block p-5 sm:p-6 flex-1">
                        <div class="flex items-start justify-between gap-3 mb-4">
                            <div class="min-w-0">
                                <h3 class="font-semibold text-slate-900 dark:text-white truncate group-hover:text-brand-600 dark:group-hover:text-brand-400 transition-colors">{{ $service->product->name }}</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 capitalize">{{ str_replace('_', ' ', $service->product->type) }}</p>
                            </div>
                            <x-status-badge :status="$service->status" type="service" />
                        </div>

                        <dl class="space-y-2.5 text-sm border-t border-slate-100 dark:border-slate-800 pt-4">
                            <div class="flex justify-between gap-2">
                                <dt class="text-slate-500 dark:text-slate-400">Service ID</dt>
                                <dd class="font-mono font-medium text-slate-900 dark:text-white">#{{ $service->id }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-slate-500 dark:text-slate-400">Billing</dt>
                                <dd class="font-medium capitalize">{{ $service->billing_cycle }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-slate-500 dark:text-slate-400">Next due</dt>
                                <dd class="font-medium
                                    @if($service->next_due_date?->isPast()) text-red-600 dark:text-red-400
                                    @elseif($service->next_due_date && $service->next_due_date->diffInDays(now()) <= 7) text-amber-600 dark:text-amber-400
                                    @else text-slate-900 dark:text-white @endif">
                                    {{ $service->next_due_date?->format('M d, Y') ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </a>

                    <div class="px-5 sm:px-6 py-4 bg-slate-50/80 dark:bg-slate-800/30 border-t border-slate-100 dark:border-slate-800 flex gap-2">
                        <a href="{{ route('customer.services.show', $service) }}" class="btn-secondary flex-1 btn-sm">Manage</a>
                        @if($canRenew)
                            <form method="POST" action="{{ route('customer.services.renew', $service) }}" class="flex-1"
                                data-confirm="Generate a renewal invoice for {{ addslashes($service->product->name) }}?">
                                @csrf
                                <button type="submit" class="btn-primary w-full btn-sm">Renew</button>
                            </form>
                        @else
                            <button disabled class="btn-secondary flex-1 btn-sm opacity-50 cursor-not-allowed" title="Renewal unavailable">Renew</button>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <x-empty-state
            title="No services yet"
            description="Deploy hosting, containers, or other infrastructure in minutes."
            action-label="Deploy your first service"
            :action-href="route('customer.select-techstack')"
            :icon="'<svg class=\"w-7 h-7\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"1.5\" d=\"M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01\"/></svg>'"
        />
    @endif
</div>
@endsection
