@extends('layouts.customer')

@section('title', 'Email Inboxes')

@section('content')
<div class="space-y-6">
    <x-page-header title="Inboxes" description="Health, limits, and mailboxes for your Email Hosting plans.">
        <x-slot:actions>
            <a href="{{ route('customer.email-hosting') }}" class="btn-primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Order Email Hosting
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($services->isEmpty())
        <x-empty-state
            title="No email plans yet"
            description="Order an Email Hosting plan, then create mailboxes for your domain."
            action-label="Browse email plans"
            action-href="{{ route('customer.email-hosting') }}"
        />
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-6">
            @foreach ($services as $service)
                @php
                    $meta = is_array($service->service_meta) ? $service->service_meta : [];
                    $mailDomain = $meta['mailcow_domain'] ?? $meta['domain'] ?? $service->external_reference;
                    $health = $healthById[$service->id] ?? null;
                @endphp
                <a href="{{ route('customer.services.email.show', $service) }}" class="ui-card ui-card-interactive block p-5 hover:border-teal-300 dark:hover:border-teal-700 transition">
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-teal-700 dark:text-teal-300">Email Hosting</p>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mt-1">{{ $service->name }}</h3>
                        </div>
                        <x-status-badge :status="$service->status" type="service" />
                    </div>
                    @if($health['domain'] ?? $mailDomain)
                        <p class="font-mono text-sm text-slate-700 dark:text-slate-300 mb-2">{{ $health['domain'] ?? $mailDomain }}</p>
                    @endif
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-3">{{ $service->customerPlanName() }}</p>

                    @if($health)
                        <dl class="grid grid-cols-2 gap-2 text-xs mb-3">
                            <div class="rounded-lg bg-slate-50 dark:bg-slate-800/60 px-2.5 py-2">
                                <dt class="text-slate-500">Mailboxes</dt>
                                <dd class="font-semibold text-slate-900 dark:text-white mt-0.5">{{ $health['mailbox_count'] }} / {{ $health['mailbox_limit'] }}</dd>
                            </div>
                            <div class="rounded-lg bg-slate-50 dark:bg-slate-800/60 px-2.5 py-2">
                                <dt class="text-slate-500">Send / day</dt>
                                <dd class="font-semibold text-slate-900 dark:text-white mt-0.5">{{ number_format($health['msgs_per_day']) }}</dd>
                            </div>
                        </dl>
                        <p class="text-xs {{ ($health['dns_ok'] ?? null) === true ? 'text-emerald-700 dark:text-emerald-300' : (($health['dns_ok'] ?? null) === false ? 'text-amber-700 dark:text-amber-300' : 'text-slate-500') }}">
                            {{ $health['dns_note'] }}
                        </p>
                    @endif

                    <p class="mt-4 text-sm font-medium text-teal-700 dark:text-teal-300">Open console →</p>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
