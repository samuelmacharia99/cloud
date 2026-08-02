@php
    $canRenew = in_array($service->status->value, ['active', 'suspended'], true);
    $payInvoice = $service->unpaidActivationInvoice();
    $manageUrl = $payInvoice
        ? route('customer.payment.select-method', $payInvoice)
        : route('customer.services.show', $service);
    $nestedContainers = $nestedContainers ?? [];
    $allProjects = $allProjects ?? collect();
@endphp

<li
    class="px-4 sm:px-5 py-4"
    x-data="{ open: false, showRename: false, showMove: false, showNewProject: false, renameName: @js($service->name), newProjectName: '' }"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <a href="{{ $manageUrl }}" class="font-medium text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400">
                {{ $service->name }}
            </a>
            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                {{ $service->product->name }}
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="capitalize">{{ str_replace('_', ' ', $service->product->type) }}</span>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                #{{ $service->id }}
            </p>
            @if(count($nestedContainers) >= 2)
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    Containers:
                    {{ implode(' · ', $nestedContainers) }}
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 shrink-0">
            <x-status-badge :status="$service->status" type="service" />
            <a href="{{ $manageUrl }}" class="btn-secondary btn-sm">{{ $payInvoice ? 'Pay' : 'Manage' }}</a>
            <div class="relative">
                <button type="button" @click="open = !open" class="btn-ghost btn-sm !px-2" aria-label="More">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                </button>
                <div
                    x-show="open"
                    @click.outside="open = false"
                    x-cloak
                    class="absolute right-0 mt-1 w-44 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-lg z-20 overflow-hidden"
                >
                    @if($canRenew)
                        <a href="{{ route('customer.services.renew', $service) }}" class="block px-3 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800">Renew</a>
                    @endif
                    <button type="button" @click="open = false; showRename = true" class="w-full text-left px-3 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800 border-t border-slate-100 dark:border-slate-800">Rename</button>
                    <button type="button" @click="open = false; showMove = true" class="w-full text-left px-3 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800 border-t border-slate-100 dark:border-slate-800">Move to project…</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Rename service --}}
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

    {{-- Move to project --}}
    <div x-show="showMove" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="showMove = false; showNewProject = false">
        <div class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6" @click.outside="showMove = false; showNewProject = false">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Move to project</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Group this service with others under one project.</p>

            <div x-show="!showNewProject" class="space-y-2">
                <form method="POST" action="{{ route('customer.services.project', $service) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="project_id" value="">
                    <button type="submit" class="w-full text-left px-3 py-2.5 rounded-lg text-sm hover:bg-slate-50 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-700">
                        No project
                    </button>
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
                <button type="button" @click="showNewProject = true" class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-medium text-brand-700 dark:text-brand-300 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-dashed border-brand-300 dark:border-brand-700">
                    + New project…
                </button>
                <button type="button" @click="showMove = false" class="btn-secondary w-full btn-sm mt-2">Cancel</button>
            </div>

            <form x-show="showNewProject" method="POST" action="{{ route('customer.projects.store') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="service_id" value="{{ $service->id }}">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Project name</label>
                    <input type="text" name="name" x-model="newProjectName" required minlength="2" maxlength="100" placeholder="Project" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800">
                </div>
                <div class="flex gap-2">
                    <button type="button" @click="showNewProject = false" class="btn-secondary flex-1 btn-sm">Back</button>
                    <button type="submit" class="btn-primary flex-1 btn-sm">Create & move</button>
                </div>
            </form>
        </div>
    </div>
</li>
