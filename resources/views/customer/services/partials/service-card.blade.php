@php
    $canRenew = in_array($service->status->value, ['active', 'suspended'], true);
    $payInvoice = $service->unpaidActivationInvoice();
    $manageUrl = $payInvoice
        ? route('customer.payment.select-method', $payInvoice)
        : route('customer.services.show', $service);
    $isWordpress = $service->isWordPressContainer();
    $nestedContainers = $nestedContainers ?? [];
    $allProjects = $allProjects ?? collect();
@endphp

<article
    draggable="true"
    data-service-id="{{ $service->id }}"
    @dragstart="
        isDragging = true;
        $event.dataTransfer.effectAllowed = 'move';
        $event.dataTransfer.setData('text/plain', String({{ $service->id }}));
        $dispatch('service-drag', { id: {{ $service->id }}, phase: 'start' });
    "
    @dragend="
        isDragging = false;
        $dispatch('service-drag', { id: null, phase: 'end' });
    "
    class="ui-card flex flex-col overflow-hidden group relative cursor-grab active:cursor-grabbing"
    :class="isDragging ? 'opacity-40 ring-2 ring-brand-400' : ''"
    x-data="{
        expanded: false,
        isDragging: false,
        showRename: false,
        showMove: false,
        showNewProject: false,
        renameName: @js($service->name),
        newProjectName: '',
    }"
>
    <div class="absolute top-3.5 left-3 z-10 text-slate-300 dark:text-slate-600 pointer-events-none" title="Drag to a project" aria-hidden="true">
        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 4a1 1 0 11-2 0 1 1 0 012 0zm0 6a1 1 0 11-2 0 1 1 0 012 0zm0 6a1 1 0 11-2 0 1 1 0 012 0zm8-12a1 1 0 11-2 0 1 1 0 012 0zm0 6a1 1 0 11-2 0 1 1 0 012 0zm0 6a1 1 0 11-2 0 1 1 0 012 0z"/></svg>
    </div>

    {{-- Compact header (always visible) --}}
    <div class="flex items-start gap-2 p-4 sm:p-5 pl-9">
        <button
            type="button"
            class="min-w-0 flex-1 text-left cursor-pointer"
            @click="expanded = !expanded"
            :aria-expanded="expanded.toString()"
            aria-controls="service-card-body-{{ $service->id }}"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="font-semibold text-slate-900 dark:text-white truncate group-hover:text-brand-600 dark:group-hover:text-brand-400 transition-colors">{{ $service->name }}</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        {{ $service->product->name }}
                        <span class="text-slate-400 dark:text-slate-500">·</span>
                        <span class="capitalize">{{ str_replace('_', ' ', $service->product->type) }}</span>
                    </p>
                    @if(count($nestedContainers) >= 2)
                        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400 truncate" x-show="!expanded">
                            {{ implode(' · ', $nestedContainers) }}
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <x-status-badge :status="$service->status" type="service" />
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 dark:text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                        <svg class="w-4 h-4 transition-transform duration-200" :class="expanded ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </span>
                </div>
            </div>
        </button>
    </div>

    {{-- Expanded details --}}
    <div
        id="service-card-body-{{ $service->id }}"
        x-show="expanded"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div class="px-4 sm:px-5 pb-4 pl-9">
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

            @if(count($nestedContainers) >= 2)
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    Containers: {{ implode(' · ', $nestedContainers) }}
                </p>
            @endif
        </div>

        <div class="px-4 sm:px-5 py-3 bg-slate-50/80 dark:bg-slate-800/30 border-t border-slate-100 dark:border-slate-800 flex flex-wrap gap-2">
            <a href="{{ $manageUrl }}" class="{{ $payInvoice ? 'btn-primary' : 'btn-secondary' }} flex-1 btn-sm min-w-[5rem]" draggable="false">
                {{ $payInvoice ? 'Pay' : 'Manage' }}
            </a>
            @if($canRenew)
                <a href="{{ route('customer.services.renew', $service) }}" class="btn-primary flex-1 btn-sm text-center min-w-[5rem]" draggable="false">Renew</a>
            @else
                <button disabled class="btn-secondary flex-1 btn-sm opacity-50 cursor-not-allowed min-w-[5rem]">Renew</button>
            @endif
            <button type="button" @click="showRename = true" class="btn-secondary flex-1 btn-sm min-w-[5rem]">Rename</button>
            @if($isWordpress)
                <a href="{{ route('customer.services.wordpress-admin', $service) }}" class="btn-primary flex-1 btn-sm text-center min-w-[5rem]" draggable="false">WP Admin</a>
            @else
                <button type="button" @click="showMove = true" class="btn-secondary flex-1 btn-sm min-w-[5rem]">Move</button>
            @endif
        </div>
    </div>

    <div x-show="showRename" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="showRename = false">
        <div class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6" @click.outside="showRename = false">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Rename service</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Personal label only — billing is unchanged.</p>
            <form method="POST" action="{{ route('customer.services.rename', $service) }}" class="space-y-4">
                @csrf
                @method('PATCH')
                <input type="text" name="name" x-model="renameName" required minlength="2" maxlength="100" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800">
                <div class="flex gap-2">
                    <button type="button" @click="showRename = false" class="btn-secondary flex-1 btn-sm">Cancel</button>
                    <button type="submit" class="btn-primary flex-1 btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>

    @unless($isWordpress)
    <div x-show="showMove" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="showMove = false; showNewProject = false">
        <div class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6" @click.outside="showMove = false; showNewProject = false">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Move to project</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Or drag the card onto a project folder.</p>
            <div x-show="!showNewProject" class="space-y-2">
                <form method="POST" action="{{ route('customer.services.project', $service) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="project_id" value="">
                    <button type="submit" class="w-full text-left px-3 py-2.5 rounded-lg text-sm hover:bg-slate-50 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-700">No project</button>
                </form>
                @foreach ($allProjects as $projectOption)
                    <form method="POST" action="{{ route('customer.services.project', $service) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="project_id" value="{{ $projectOption->id }}">
                        <button type="submit" class="w-full text-left px-3 py-2.5 rounded-lg text-sm hover:bg-slate-50 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-700 {{ (int) $service->project_id === (int) $projectOption->id ? 'ring-2 ring-brand-500' : '' }}">
                            {{ $projectOption->name }}
                        </button>
                    </form>
                @endforeach
                <button type="button" @click="showNewProject = true" class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-medium text-brand-700 dark:text-brand-300 border border-dashed border-brand-300 dark:border-brand-700">+ New project…</button>
                <button type="button" @click="showMove = false" class="btn-secondary w-full btn-sm mt-2">Cancel</button>
            </div>
            <form x-show="showNewProject" method="POST" action="{{ route('customer.projects.store') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="service_id" value="{{ $service->id }}">
                <input type="text" name="name" x-model="newProjectName" required minlength="2" maxlength="100" placeholder="Project" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800">
                <div class="flex gap-2">
                    <button type="button" @click="showNewProject = false" class="btn-secondary flex-1 btn-sm">Back</button>
                    <button type="submit" class="btn-primary flex-1 btn-sm">Create & move</button>
                </div>
            </form>
        </div>
    </div>
    @endunless
</article>
