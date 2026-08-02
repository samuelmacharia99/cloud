@php
    $canRenew = in_array($service->status->value, ['active', 'suspended'], true);
    $payInvoice = $service->unpaidActivationInvoice();
    $manageUrl = $payInvoice
        ? route('customer.payment.select-method', $payInvoice)
        : route('customer.services.show', $service);
    $isWordpress = $service->product?->type === 'container_hosting'
        && ($service->product?->containerTemplate?->slug ?? '') === 'wordpress';
    $typeLabel = str_replace('_', ' ', $service->product->type ?? '');
    $statusDot = match ($service->status->value) {
        'active' => 'bg-emerald-500',
        'suspended' => 'bg-amber-500',
        'pending', 'provisioning' => 'bg-sky-500',
        'failed' => 'bg-red-500',
        default => 'bg-slate-400',
    };
    $nestedContainers = $nestedContainers ?? [];
@endphp

<tr
    class="group/row hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
    x-data="{ showRenameModal: false, renameName: @js($service->name), actionsOpen: false }"
>
    <td class="py-3.5 pl-4 pr-3 sm:pl-5">
        <div class="flex items-start gap-3 min-w-0">
            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $statusDot }}" title="{{ ucfirst($service->status->value) }}"></span>
            <div class="min-w-0">
                <a href="{{ $manageUrl }}" class="font-medium text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400 truncate block">
                    {{ $service->name }}
                </a>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 font-mono">#{{ $service->id }}</p>
            </div>
        </div>
    </td>
    <td class="hidden sm:table-cell px-3 py-3.5">
        <x-status-badge :status="$service->status" type="service" />
    </td>
    <td class="hidden md:table-cell px-3 py-3.5">
        <div class="text-sm text-slate-700 dark:text-slate-300">{{ $service->product->name }}</div>
        <div class="text-xs text-slate-500 dark:text-slate-400 capitalize">{{ $typeLabel }}</div>
    </td>
    <td class="hidden lg:table-cell px-3 py-3.5 text-sm capitalize text-slate-600 dark:text-slate-300">
        {{ $service->billing_cycle }}
    </td>
    <td class="hidden lg:table-cell px-3 py-3.5 text-sm
        @if($service->next_due_date?->isPast()) text-red-600 dark:text-red-400 font-medium
        @elseif($service->next_due_date && $service->next_due_date->diffInDays(now()) <= 7) text-amber-600 dark:text-amber-400 font-medium
        @else text-slate-600 dark:text-slate-300 @endif">
        {{ $service->next_due_date?->format('M d, Y') ?? '—' }}
    </td>
    <td class="py-3.5 pl-3 pr-4 sm:pr-5 text-right">
        <div class="relative inline-flex items-center gap-1">
            <a href="{{ $manageUrl }}" class="btn-secondary btn-sm hidden xl:inline-flex">
                {{ $payInvoice ? 'Pay' : 'Manage' }}
            </a>
            <button
                type="button"
                @click="actionsOpen = !actionsOpen"
                class="btn-ghost btn-sm !px-2"
                aria-label="Service actions"
            >
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
            </button>
            <div
                x-show="actionsOpen"
                @click.outside="actionsOpen = false"
                x-cloak
                class="absolute right-0 top-full mt-1 w-48 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-lg z-20 overflow-hidden text-left"
            >
                <a href="{{ $manageUrl }}" class="block px-3 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800 font-medium">
                    {{ $payInvoice ? 'Pay invoice' : 'Manage' }}
                </a>
                @if($canRenew)
                    <a href="{{ route('customer.services.renew', $service) }}" class="block px-3 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800 border-t border-slate-100 dark:border-slate-800">
                        Renew
                    </a>
                @endif
                <button
                    type="button"
                    @click="actionsOpen = false; showRenameModal = true; renameName = @js($service->name)"
                    class="w-full text-left px-3 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800 border-t border-slate-100 dark:border-slate-800"
                >
                    Rename
                </button>
                @if($isWordpress)
                    <a href="{{ route('customer.services.wordpress-admin', $service) }}" class="block px-3 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800 border-t border-slate-100 dark:border-slate-800">
                        WP Admin
                    </a>
                @endif
            </div>
        </div>

        <div
            x-show="showRenameModal"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
            @keydown.escape.window="showRenameModal = false"
        >
            <div
                class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6 text-left"
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
    </td>
</tr>

@foreach ($nestedContainers as $containerLabel)
    <tr class="bg-slate-50/40 dark:bg-slate-900/20">
        <td class="py-2 pl-4 pr-3 sm:pl-5" colspan="1">
            <a href="{{ $manageUrl }}" class="flex items-center gap-3 pl-5 text-sm text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400">
                <span class="relative flex h-4 w-4 shrink-0 items-center justify-center">
                    <span class="absolute left-1/2 top-0 h-full w-px -translate-x-1/2 bg-slate-300 dark:bg-slate-600"></span>
                    <span class="absolute left-1/2 top-1/2 h-px w-2 bg-slate-300 dark:bg-slate-600"></span>
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    {{ $containerLabel }}
                </span>
            </a>
        </td>
        <td class="hidden sm:table-cell px-3 py-2">
            <span class="text-xs text-slate-500 dark:text-slate-400">Container</span>
        </td>
        <td class="hidden md:table-cell px-3 py-2 text-xs text-slate-500 dark:text-slate-400">
            Part of {{ $service->name }}
        </td>
        <td class="hidden lg:table-cell px-3 py-2"></td>
        <td class="hidden lg:table-cell px-3 py-2"></td>
        <td class="py-2 pl-3 pr-4 sm:pr-5 text-right">
            <a href="{{ $manageUrl }}" class="text-xs font-medium text-brand-600 dark:text-brand-400 hover:underline">Open</a>
        </td>
    </tr>
@endforeach
