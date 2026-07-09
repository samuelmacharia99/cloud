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
                @php
                    $canRenew = in_array($service->status->value, ['active', 'suspended']);
                    $payInvoice = $service->unpaidActivationInvoice();
                    $manageUrl = $payInvoice
                        ? route('customer.payment.select-method', $payInvoice)
                        : route('customer.services.show', $service);
                @endphp
                <article
                    class="ui-card ui-card-interactive flex flex-col overflow-hidden group"
                    x-data="{ showRenameModal: false, renameName: @js($service->name) }"
                >
                    <a href="{{ $manageUrl }}" class="block p-5 sm:p-6 flex-1">
                        <div class="flex items-start justify-between gap-3 mb-4">
                            <div class="min-w-0">
                                <h3 class="font-semibold text-slate-900 dark:text-white truncate group-hover:text-brand-600 dark:group-hover:text-brand-400 transition-colors">{{ $service->name }}</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ $service->product->name }}
                                    <span class="text-slate-400 dark:text-slate-500">·</span>
                                    <span class="capitalize">{{ str_replace('_', ' ', $service->product->type) }}</span>
                                </p>
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
                        <a href="{{ $manageUrl }}" class="{{ $payInvoice ? 'btn-primary' : 'btn-secondary' }} flex-1 btn-sm">
                            {{ $payInvoice ? 'Pay invoice' : 'Manage' }}
                        </a>
                        @if($canRenew)
                            <a href="{{ route('customer.services.renew', $service) }}" class="btn-primary flex-1 btn-sm text-center">
                                Renew
                            </a>
                        @else
                            <button disabled class="btn-secondary flex-1 btn-sm opacity-50 cursor-not-allowed" title="Renewal unavailable">Renew</button>
                        @endif
                        <button
                            type="button"
                            @click="showRenameModal = true; renameName = @js($service->name)"
                            class="btn-secondary flex-1 btn-sm"
                        >
                            Rename
                        </button>
                    </div>

                    <div
                        x-show="showRenameModal"
                        x-cloak
                        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                        @keydown.escape.window="showRenameModal = false"
                    >
                        <div
                            class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6"
                            @click.outside="showRenameModal = false"
                        >
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Rename service</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
                                Choose a label for your own reference. This does not change your plan or billing.
                            </p>
                            <form method="POST" action="{{ route('customer.services.rename', $service) }}" class="space-y-4">
                                @csrf
                                @method('PATCH')
                                <div>
                                    <label for="rename-name-{{ $service->id }}" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Service name</label>
                                    <input
                                        id="rename-name-{{ $service->id }}"
                                        type="text"
                                        name="name"
                                        x-model="renameName"
                                        required
                                        minlength="2"
                                        maxlength="100"
                                        class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-brand-500"
                                    >
                                </div>
                                <div class="flex gap-2">
                                    <button type="button" @click="showRenameModal = false" class="btn-secondary flex-1 btn-sm">Cancel</button>
                                    <button type="submit" class="btn-primary flex-1 btn-sm">Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <x-empty-state
            title="No services yet"
            description="Deploy hosting, containers, or other infrastructure in minutes."
            action-label="Deploy your first service"
            action-href="{{ route('customer.select-techstack') }}"
        />
    @endif
</div>
@endsection
