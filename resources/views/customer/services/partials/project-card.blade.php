@php
    $isProject = ($group['type'] ?? '') === 'project';
    $project = $isProject ? $group['project'] : null;
    $groupServices = $group['services'];
    $containers = $group['containers'] ?? [];
    $primaryContainer = $isProject
        ? $groupServices->first(fn ($s) => $s->isContainerHosting())
        : null;
    $canRemoveProject = $isProject && $groupServices->contains(fn ($s) => $s->isContainerHosting())
        && ! $groupServices->contains(function ($s) {
            $status = $s->status->value ?? (string) $s->status;

            return ! $s->isContainerHosting() && ! in_array($status, ['terminated', 'cancelled'], true);
        });
    $dropKey = $isProject ? (string) $project->id : 'none';
    $resourceCount = $isProject ? $project->resourceCount() : $groupServices->count();
    $billingService = $isProject ? $project->resolvedBillingService() : null;
    $planLimits = $isProject ? $project->includedPlanLimits() : null;
    $canDeployIncluded = $isProject && $project->canDeployIncludedWorkload();
    $openByDefault = $isProject && request()->integer('project') === (int) $project->id;
@endphp

<article
    class="flex flex-col rounded-2xl border bg-white dark:bg-[#1a1a1a] transition-all duration-200 min-h-[11.5rem]"
    @if($isProject)
    x-data="{
        open: @js($openByDefault),
        menu: false,
        showRenameProject: false,
        showRemoveProject: false,
        renameProjectName: @js($project->name),
        confirmName: '',
    }"
    @else
    x-data="{ open: false }"
    @endif
    :class="[
        dropTarget === @js($dropKey) && draggingId
            ? 'border-brand-400 dark:border-brand-500 ring-2 ring-brand-300/60 dark:ring-brand-700/60'
            : 'border-slate-200 dark:border-white/10',
        open ? 'sm:col-span-2 xl:col-span-4' : '',
    ]"
    @dragover.prevent="onDragOver($event, @js($dropKey)); if (draggingId) open = true;"
    @dragleave="onDragLeave($event, @js($dropKey))"
    @drop.prevent="onDrop($event, @js($isProject ? $project->id : null))"
>
    <div class="flex-1 p-5">
        <div class="flex items-start justify-between gap-3">
            <button
                type="button"
                class="min-w-0 flex-1 text-left"
                @click="open = !open"
                :aria-expanded="open.toString()"
                aria-controls="{{ $isProject ? 'project-services-'.$project->id : 'project-services-none' }}"
            >
                <div class="flex items-center gap-2 min-w-0">
                    <span class="text-slate-500 dark:text-slate-300 shrink-0" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 16h14l-1.2-6.5L16 11l-4-6-4 6-1.8-1.5L5 16z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 16h12v2a1 1 0 01-1 1H7a1 1 0 01-1-1v-2z"/>
                        </svg>
                    </span>
                    <h2 class="font-semibold text-slate-900 dark:text-white truncate">
                        {{ $isProject ? $project->name : 'No project' }}
                    </h2>
                </div>
            </button>

            @if($isProject)
                <div class="flex items-center gap-1 shrink-0">
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:text-slate-800 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10"
                        @click.stop="showRenameProject = true"
                        aria-label="Rename project"
                        title="Rename project"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                        </svg>
                    </button>
                    @if($canRemoveProject)
                        <div class="relative" @click.outside="menu = false">
                            <button
                                type="button"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10"
                                @click.stop="menu = !menu"
                                aria-label="Project actions"
                            >
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                    <path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                            </button>
                            <div
                                x-show="menu"
                                x-cloak
                                class="absolute right-0 mt-1 w-44 rounded-xl border border-slate-200 dark:border-white/10 bg-white dark:bg-[#222] shadow-xl z-20 py-1"
                            >
                                <button type="button" @click="menu = false; showRemoveProject = true" class="w-full text-left px-3 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30">
                                    Remove project
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            @if($isProject)
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 dark:bg-white/10 text-slate-600 dark:text-slate-200">Owner</span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 dark:bg-white/10 text-slate-600 dark:text-slate-200">
                    {{ $project->memberCount() }} {{ Str::plural('Member', $project->memberCount()) }}
                </span>
            @else
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 dark:bg-white/10 text-slate-600 dark:text-slate-200">Ungrouped</span>
            @endif
        </div>

        @if($isProject)
            <div class="mt-4">
                @if($canDeployIncluded)
                    <a
                        href="{{ route('customer.projects.deploy', $project) }}"
                        class="inline-flex items-center justify-center w-full px-3 py-2 rounded-lg text-sm font-medium bg-ink-950 text-white hover:bg-ink-800 dark:bg-white dark:text-ink-950 dark:hover:bg-slate-100 transition"
                        @click.stop
                    >
                        Deploy new service
                    </a>
                @else
                    <a
                        href="{{ route('customer.projects.deploy', $project) }}"
                        class="inline-flex items-center justify-center w-full px-3 py-2 rounded-lg text-sm font-medium bg-ink-950 text-white hover:bg-ink-800 dark:bg-white dark:text-ink-950 dark:hover:bg-slate-100 transition"
                        @click.stop
                    >
                        Choose a plan
                    </a>
                @endif
            </div>
        @endif
    </div>

    <button
        type="button"
        class="flex items-center justify-between gap-2 px-5 py-3 border-t border-slate-100 dark:border-white/10 text-sm text-slate-600 dark:text-slate-300"
        @click="open = !open"
    >
        <span>
            {{ $resourceCount }} {{ Str::plural('Resource', $resourceCount) }}
            <span x-show="!open" x-cloak class="text-slate-400"> · collapsed</span>
        </span>
        <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
    </button>

    <div
        id="{{ $isProject ? 'project-services-'.$project->id : 'project-services-none' }}"
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        class="border-t border-slate-100 dark:border-white/10 p-5 space-y-4"
    >
        @if($isProject)
            @if($billingService && $planLimits)
                <div class="rounded-xl bg-slate-50 dark:bg-white/5 px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                    <p class="font-medium text-slate-900 dark:text-white">{{ $billingService->customerPlanName() }} · {{ $billingService->billing_cycle }}</p>
                    <p class="mt-0.5">
                        Included {{ rtrim(rtrim(number_format($planLimits['cpu'], 2), '0'), '.') }} CPU ·
                        {{ rtrim(rtrim(number_format($planLimits['memory_mb'] / 1024, 2), '0'), '.') }} GB RAM
                        @if($planLimits['disk_gb'] > 0)
                            · {{ rtrim(rtrim(number_format($planLimits['disk_gb'], 2), '0'), '.') }} GB disk
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Extra services on this project are not billed again. Usage above these specs is metered on the next {{ $billingService->billing_cycle }} invoice.
                    </p>
                </div>
            @elseif($isProject)
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Choose a plan to start this project. Additional services after that stay on the same bill.
                </p>
            @endif

        @else
            <p class="text-sm text-slate-500 dark:text-slate-400">Drag cards here to ungroup, or onto a project.</p>
        @endif

        @if($groupServices->isNotEmpty())
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 min-h-[4rem]">
                @foreach ($groupServices as $service)
                    @include('customer.services.partials.service-card', [
                        'service' => $service,
                        'allProjects' => $projects,
                        'nestedContainers' => (
                            $primaryContainer
                            && $service->id === $primaryContainer->id
                            && count($containers) >= 2
                        ) ? $containers : [],
                    ])
                @endforeach
            </div>
        @elseif($isProject)
            <p class="text-sm text-slate-500 dark:text-slate-400">No services in this project yet.</p>
        @endif
    </div>

    @if($isProject)
        <div x-show="showRenameProject" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="showRenameProject = false">
            <div class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6" @click.outside="showRenameProject = false">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Rename project</h3>
                <form method="POST" action="{{ route('customer.projects.rename', $project) }}" class="space-y-4">
                    @csrf
                    @method('PATCH')
                    <input type="text" name="name" x-model="renameProjectName" required minlength="2" maxlength="100" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800">
                    <div class="flex gap-2">
                        <button type="button" @click="showRenameProject = false" class="btn-secondary flex-1 btn-sm">Cancel</button>
                        <button type="submit" class="btn-primary flex-1 btn-sm">Save</button>
                    </div>
                </form>
            </div>
        </div>

        @if($canRemoveProject)
            <div x-show="showRemoveProject" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="showRemoveProject = false">
                <div class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6" @click.outside="showRemoveProject = false">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Remove project</h3>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                        This permanently deletes every Application Hosting site in <strong>{{ $project->name }}</strong>, including containers and files. This cannot be undone. Email Hosting is not deleted this way.
                    </p>
                    <form method="POST" action="{{ route('customer.projects.destroy', $project) }}" class="space-y-4">
                        @csrf
                        @method('DELETE')
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">
                            Type <span class="font-mono">{{ $project->name }}</span> to confirm
                            <input type="text" name="confirm_name" x-model="confirmName" required autocomplete="off" class="mt-1.5 w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800">
                        </label>
                        <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input type="checkbox" name="confirm" value="1" required class="mt-1 rounded border-slate-300 dark:border-slate-600">
                            <span>I understand these Application Hosting sites and their files will be permanently deleted.</span>
                        </label>
                        <div class="flex gap-2">
                            <button type="button" @click="showRemoveProject = false" class="btn-secondary flex-1 btn-sm">Keep project</button>
                            <button
                                type="submit"
                                class="flex-1 btn-sm rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium px-3 py-2 disabled:opacity-40"
                                :disabled="confirmName !== @js($project->name)"
                            >Delete sites</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endif
</article>
