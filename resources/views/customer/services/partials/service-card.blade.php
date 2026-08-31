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
    class="group relative flex flex-col overflow-hidden rounded-xl border border-ink-200/80 dark:border-ink-700/60 bg-white/85 dark:bg-ink-900/45 transition-all duration-200 cursor-grab active:cursor-grabbing hover:border-brand-300/80 dark:hover:border-brand-700/60"
    :class="isDragging ? 'opacity-40 ring-2 ring-brand-400' : ''"
    x-data="{
        expanded: false,
        isDragging: false,
        showRename: false,
        showMove: false,
        showNewProject: false,
        renameName: @js($service->customerServiceName()),
        newProjectName: '',
    }"
>
    <span class="pointer-events-none absolute left-2.5 top-4 text-ink-300 dark:text-ink-600 opacity-70" title="Drag to a project" aria-hidden="true">
        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path d="M7 4a1 1 0 11-2 0 1 1 0 012 0zm0 6a1 1 0 11-2 0 1 1 0 012 0zm0 6a1 1 0 11-2 0 1 1 0 012 0zm8-12a1 1 0 11-2 0 1 1 0 012 0zm0 6a1 1 0 11-2 0 1 1 0 012 0zm0 6a1 1 0 11-2 0 1 1 0 012 0z"/></svg>
    </span>

    <button
        type="button"
        class="w-full pl-8 pr-3.5 py-3.5 text-left"
        @click="expanded = !expanded"
        :aria-expanded="expanded.toString()"
        aria-controls="service-card-body-{{ $service->id }}"
    >
        <div class="flex items-start justify-between gap-2.5">
            <div class="min-w-0">
                <h3 class="truncate text-sm font-semibold text-ink-950 dark:text-white transition-colors group-hover:text-brand-700 dark:group-hover:text-brand-300">
                    {{ $service->customerServiceName() }}
                </h3>
                <p class="mt-0.5 truncate text-xs text-ink-500 dark:text-ink-400">
                    {{ $service->customerPlanName() }}
                    <span class="text-ink-300 dark:text-ink-600">·</span>
                    {{ $service->customerPlanTypeLabel() }}
                </p>
                @if(count($nestedContainers) >= 2)
                    <p class="mt-1 truncate text-[11px] text-ink-400 dark:text-ink-500" x-show="!expanded">
                        {{ implode(' · ', $nestedContainers) }}
                    </p>
                @endif
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
                <x-status-badge :status="$service->status" type="service" />
                <svg class="w-4 h-4 text-ink-400 transition-transform duration-200" :class="expanded ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
        </div>
    </button>

    <div
        id="service-card-body-{{ $service->id }}"
        x-show="expanded"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
    >
        <dl class="mx-3.5 space-y-2 border-t border-ink-100 dark:border-ink-800/80 py-3 text-xs">
            <div class="flex items-center justify-between gap-2">
                <dt class="text-ink-500 dark:text-ink-400">Service ID</dt>
                <dd class="font-mono font-semibold text-ink-900 dark:text-white">#{{ $service->id }}</dd>
            </div>
            <div class="flex items-center justify-between gap-2">
                <dt class="text-ink-500 dark:text-ink-400">Billing</dt>
                <dd class="font-semibold capitalize text-ink-900 dark:text-white">{{ $service->billing_cycle }}</dd>
            </div>
            <div class="flex items-center justify-between gap-2">
                <dt class="text-ink-500 dark:text-ink-400">Next due</dt>
                <dd class="font-semibold
                    @if($service->next_due_date?->isPast()) text-red-600 dark:text-red-400
                    @elseif($service->next_due_date && $service->next_due_date->diffInDays(now()) <= 7) text-amber-600 dark:text-amber-400
                    @else text-ink-900 dark:text-white @endif">
                    {{ $service->next_due_date?->format('M j, Y') ?? '—' }}
                </dd>
            </div>
            @if(count($nestedContainers) >= 2)
                <div class="flex items-start justify-between gap-2">
                    <dt class="text-ink-500 dark:text-ink-400 shrink-0">Containers</dt>
                    <dd class="text-right font-medium text-ink-700 dark:text-ink-200">{{ implode(' · ', $nestedContainers) }}</dd>
                </div>
            @endif
        </dl>

        <div class="grid grid-cols-2 gap-2 border-t border-ink-100 dark:border-ink-800/80 bg-ink-50/70 dark:bg-ink-950/30 p-3">
            <a href="{{ $manageUrl }}" class="{{ $payInvoice ? 'btn-primary' : 'btn-secondary' }} btn-sm w-full" draggable="false">
                {{ $payInvoice ? 'Pay' : 'Manage' }}
            </a>
            @if($canRenew)
                <a href="{{ route('customer.services.renew', $service) }}" class="btn-primary btn-sm w-full" draggable="false">Renew</a>
            @else
                <button disabled class="btn-secondary btn-sm w-full opacity-50">Renew</button>
            @endif
            <button type="button" @click="showRename = true" class="btn-secondary btn-sm w-full">Rename</button>
            @if($isWordpress)
                <a href="{{ route('customer.services.wordpress-admin', $service) }}" class="btn-primary btn-sm w-full" draggable="false">WP Admin</a>
            @else
                <button type="button" @click="showMove = true" class="btn-secondary btn-sm w-full">Move</button>
            @endif
        </div>
    </div>

    <div x-show="showRename" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink-950/60 backdrop-blur-sm" @keydown.escape.window="showRename = false">
        <div class="ui-card w-full max-w-md p-6" @click.outside="showRename = false">
            <h3 class="font-display text-lg font-bold text-ink-950 dark:text-white">Rename service</h3>
            <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">Personal label only — billing is unchanged.</p>
            <form method="POST" action="{{ route('customer.services.rename', $service) }}" class="mt-4 space-y-4">
                @csrf
                @method('PATCH')
                <input type="text" name="name" x-model="renameName" required minlength="2" maxlength="100" class="w-full px-4 py-2.5">
                <div class="flex gap-2">
                    <button type="button" @click="showRename = false" class="btn-secondary flex-1 btn-sm">Cancel</button>
                    <button type="submit" class="btn-primary flex-1 btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>

    @unless($isWordpress)
    <div x-show="showMove" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink-950/60 backdrop-blur-sm" @keydown.escape.window="showMove = false; showNewProject = false">
        <div class="ui-card w-full max-w-md p-6" @click.outside="showMove = false; showNewProject = false">
            <h3 class="font-display text-lg font-bold text-ink-950 dark:text-white">Move to project</h3>
            <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">Or drag the card onto a project.</p>
            <div x-show="!showNewProject" class="mt-4 space-y-2">
                <form method="POST" action="{{ route('customer.services.project', $service) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="project_id" value="">
                    <button type="submit" class="w-full rounded-xl border border-ink-200 dark:border-ink-700 px-3.5 py-2.5 text-left text-sm font-medium text-ink-700 dark:text-ink-200 transition-colors hover:border-brand-300 hover:bg-brand-50/60 dark:hover:bg-white/5">No project</button>
                </form>
                @foreach ($allProjects as $projectOption)
                    <form method="POST" action="{{ route('customer.services.project', $service) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="project_id" value="{{ $projectOption->id }}">
                        <button type="submit" class="w-full rounded-xl border px-3.5 py-2.5 text-left text-sm font-medium transition-colors hover:bg-brand-50/60 dark:hover:bg-white/5 {{ (int) $service->project_id === (int) $projectOption->id ? 'border-brand-400 text-brand-800 dark:text-brand-200 bg-brand-50/70 dark:bg-brand-950/30' : 'border-ink-200 dark:border-ink-700 text-ink-700 dark:text-ink-200 hover:border-brand-300' }}">
                            {{ $projectOption->name }}
                        </button>
                    </form>
                @endforeach
                <button type="button" @click="showNewProject = true" class="w-full rounded-xl border border-dashed border-brand-300 dark:border-brand-800 px-3.5 py-2.5 text-left text-sm font-semibold text-brand-700 dark:text-brand-300 hover:bg-brand-50/60 dark:hover:bg-brand-950/25">+ New project…</button>
                <button type="button" @click="showMove = false" class="btn-secondary btn-sm mt-2 w-full">Cancel</button>
            </div>
            <form x-show="showNewProject" x-cloak method="POST" action="{{ route('customer.projects.store') }}" class="mt-4 space-y-4">
                @csrf
                <input type="hidden" name="service_id" value="{{ $service->id }}">
                <input type="text" name="name" x-model="newProjectName" required minlength="2" maxlength="100" placeholder="Project" class="w-full px-4 py-2.5">
                <div class="flex gap-2">
                    <button type="button" @click="showNewProject = false" class="btn-secondary flex-1 btn-sm">Back</button>
                    <button type="submit" class="btn-primary flex-1 btn-sm">Create &amp; move</button>
                </div>
            </form>
        </div>
    </div>
    @endunless
</article>
