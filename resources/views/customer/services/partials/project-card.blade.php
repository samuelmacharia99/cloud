@php
    $isProject = ($group['type'] ?? '') === 'project';
    $project = $isProject ? $group['project'] : null;
    $groupServices = $group['services'];
    $billingService = $isProject ? $project->resolvedBillingService() : null;
    $planLimits = $isProject ? $project->includedPlanLimits() : null;
    $canDeployIncluded = $isProject && $project->canDeployIncludedWorkload();
    $primaryActionLabel = $canDeployIncluded ? 'Deploy new service' : 'Choose a plan';
    $nextDue = $billingService?->next_due_date;
    $resourceCount = $isProject ? $project->resourceCount() : $groupServices->count();
    $dropKey = $isProject ? (string) $project->id : 'none';
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
@endphp

<article
    class="relative flex flex-col overflow-visible transition-all duration-300 {{ $isProject ? 'ui-card hover:border-brand-300/70 dark:hover:border-brand-700/50' : 'rounded-2xl border border-dashed border-ink-300/80 dark:border-ink-700/70 bg-white/45 dark:bg-ink-900/25' }}"
    @if($isProject)
    x-data="{
        menu: false,
        showRenameProject: false,
        showRemoveProject: false,
        renameProjectName: @js($project->name),
        confirmName: '',
    }"
    @else
    x-data="{ open: false }"
    @endif
    :class="dropTarget === @js($dropKey) && draggingId
        ? 'border-brand-400/80 dark:border-brand-500/60 ring-2 ring-brand-300/60 dark:ring-brand-600/50'
        : ''"
    @dragover.prevent="onDragOver($event, @js($dropKey));"
    @dragleave="onDragLeave($event, @js($dropKey))"
    @drop.prevent="onDrop($event, @js($isProject ? $project->id : null))"
>
    <div
        x-show="draggingId && dropTarget === @js($dropKey)"
        x-cloak
        class="absolute inset-0 z-10 flex items-center justify-center rounded-2xl bg-brand-50/85 dark:bg-brand-950/70 pointer-events-none"
    >
        <span class="inline-flex items-center gap-2 rounded-full bg-white/90 dark:bg-ink-900/90 px-3.5 py-1.5 text-xs font-semibold text-brand-700 dark:text-brand-200 shadow-soft">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
            </svg>
            Drop to move here
        </span>
    </div>

    <div class="flex-1 p-5">
        <div class="flex items-start gap-3">
            @if($isProject)
                <a href="{{ route('customer.projects.show', $project) }}" class="flex min-w-0 flex-1 items-start gap-3 text-left group/card">
            @else
                <button type="button" class="flex min-w-0 flex-1 items-start gap-3 text-left" @click="open = !open" :aria-expanded="open.toString()">
            @endif
                @if($isProject)
                    <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-ink-950 ring-1 ring-brand-300/50 shadow-glow transition-transform group-hover/card:scale-105" aria-hidden="true">
                        <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 7.5l-9-4.5-9 4.5m18 0l-9 4.5m9-4.5v9l-9 4.5m0-9L3 7.5m9 4.5v9m-9-13.5v9l9 4.5"/>
                        </svg>
                    </span>
                @else
                    <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-dashed border-ink-300 dark:border-ink-600 text-ink-400 dark:text-ink-500" aria-hidden="true">
                        <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3.75 9.75h16.5M3.75 6.75A2.25 2.25 0 016 4.5h3.13c.6 0 1.17.24 1.6.66l1.11 1.09h6.16A2.25 2.25 0 0120.25 8.5v8.75a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6.75z"/>
                        </svg>
                    </span>
                @endif

                <span class="min-w-0">
                    <span class="block font-display font-bold tracking-tight text-ink-950 dark:text-white truncate {{ $isProject ? 'group-hover/card:text-brand-700 dark:group-hover/card:text-brand-300' : '' }}" title="{{ $isProject ? $project->name : 'No project' }}">
                        {{ $isProject ? $project->name : 'No project' }}
                    </span>
                    <span class="mt-0.5 block text-xs text-ink-500 dark:text-ink-400 truncate">
                        @if($billingService)
                            {{ $billingService->customerPlanName() }} <span class="text-ink-300 dark:text-ink-600">·</span> {{ $billingService->billing_cycle }}
                        @elseif($isProject)
                            No plan yet
                        @else
                            Not grouped under a project
                        @endif
                    </span>
                </span>
            @if($isProject)
                </a>
            @else
                </button>
            @endif

            @if($isProject)
                <div class="flex shrink-0 items-center gap-0.5">
                    <button
                        type="button"
                        class="action-icon-btn w-8 h-8 text-ink-400 hover:text-ink-900 dark:hover:text-white"
                        @click.stop="showRenameProject = true"
                        aria-label="Rename project"
                        title="Rename project"
                    >
                        <svg class="!w-4 !h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                        </svg>
                    </button>
                    @php
                        $canRemoveProject = $groupServices->contains(fn ($s) => $s->isContainerHosting())
                            && ! $groupServices->contains(function ($s) {
                                $status = $s->status->value ?? (string) $s->status;

                                return ! $s->isContainerHosting() && ! in_array($status, ['terminated', 'cancelled'], true);
                            });
                    @endphp
                    @if($canRemoveProject)
                        <div class="relative" @click.outside="menu = false">
                            <button
                                type="button"
                                class="action-icon-btn w-8 h-8 text-ink-400 hover:text-ink-900 dark:hover:text-white"
                                @click.stop="menu = !menu"
                                aria-label="Project actions"
                            >
                                <svg class="!w-[18px] !h-[18px]" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                    <path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                            </button>
                            <div
                                x-show="menu"
                                x-cloak
                                x-transition.origin.top.right
                                class="ui-card absolute right-0 z-30 mt-1 w-48 p-1"
                            >
                                <button type="button" @click="menu = false; showRemoveProject = true" class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40">
                                    Remove project
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <div class="mt-3.5 flex flex-wrap items-center gap-1.5">
            @if($billingService)
                <x-status-badge :status="$billingService->status" type="service" />
            @endif
            @if($isProject)
                <span class="status-pill bg-ink-100/90 dark:bg-white/10 text-ink-600 dark:text-ink-200">Owner</span>
                <span class="status-pill bg-ink-100/90 dark:bg-white/10 text-ink-600 dark:text-ink-200">{{ $resourceCount }} {{ Str::plural('Resource', $resourceCount) }}</span>
            @else
                <span class="status-pill bg-ink-100/90 dark:bg-white/10 text-ink-600 dark:text-ink-200">Ungrouped</span>
            @endif
        </div>

        @if($isProject)
            @if($planLimits)
                <div class="ui-soft-inset mt-4 px-3.5 py-3">
                    <dl class="grid grid-cols-3 divide-x divide-ink-200/70 dark:divide-ink-700/60 text-center">
                        <div class="px-1">
                            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-ink-400 dark:text-ink-500">vCPU</dt>
                            <dd class="mt-0.5 text-sm font-bold tabular-nums text-ink-950 dark:text-white">{{ $trim($planLimits['cpu']) }}</dd>
                        </div>
                        <div class="px-1">
                            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-ink-400 dark:text-ink-500">RAM</dt>
                            <dd class="mt-0.5 text-sm font-bold tabular-nums text-ink-950 dark:text-white">{{ $trim($planLimits['memory_mb'] / 1024) }} GB</dd>
                        </div>
                        <div class="px-1">
                            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-ink-400 dark:text-ink-500">Disk</dt>
                            <dd class="mt-0.5 text-sm font-bold tabular-nums text-ink-950 dark:text-white">
                                {{ $planLimits['disk_gb'] > 0 ? $trim($planLimits['disk_gb']).' GB' : '—' }}
                            </dd>
                        </div>
                    </dl>
                    @if($nextDue)
                        <p class="mt-2.5 flex items-center gap-1.5 border-t border-ink-200/70 dark:border-ink-700/60 pt-2 text-[11px] text-ink-500 dark:text-ink-400">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 5.25h15a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75V6a.75.75 0 01.75-.75z"/>
                            </svg>
                            Plan renews {{ $nextDue->format('M j, Y') }}
                        </p>
                    @endif
                </div>
            @else
                <p class="mt-4 rounded-xl border border-dashed border-ink-300/80 dark:border-ink-700/70 px-3.5 py-3 text-xs text-ink-500 dark:text-ink-400">
                    Pick a plan to start this project. Everything you deploy afterwards shares that one bill.
                </p>
            @endif

            <div class="mt-4 flex items-center gap-2">
                <a
                    href="{{ route('customer.projects.show', $project) }}"
                    class="btn-secondary btn-sm flex-1"
                >
                    Open project
                </a>
                <a
                    href="{{ route('customer.projects.deploy', $project) }}"
                    class="btn-primary btn-sm flex-1"
                    @click.stop
                >
                    {{ $primaryActionLabel }}
                </a>
            </div>
        @else
            <p class="mt-4 rounded-xl border border-dashed border-ink-300/80 dark:border-ink-700/70 px-3.5 py-3 text-xs text-ink-500 dark:text-ink-400">
                Drag any card onto a project to group it under that project's plan.
            </p>
        @endif
    </div>

    @if($isProject)
        <a
            href="{{ route('customer.projects.show', $project) }}"
            class="flex items-center justify-between gap-2 border-t border-ink-100/90 dark:border-ink-800/70 px-5 py-3 text-sm transition-colors hover:bg-brand-50/50 dark:hover:bg-white/5 rounded-b-2xl"
        >
            <span class="inline-flex items-center gap-2 font-medium text-ink-700 dark:text-ink-200">
                <svg class="w-4 h-4 shrink-0 text-ink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
                <span>{{ $resourceCount }} {{ Str::plural('Resource', $resourceCount) }}</span>
            </span>
            <span class="text-xs font-medium text-brand-600 dark:text-brand-400">View all</span>
        </a>
    @else
        <button
            type="button"
            class="flex items-center justify-between gap-2 border-t border-ink-100/90 dark:border-ink-800/70 px-5 py-3 text-sm transition-colors hover:bg-brand-50/50 dark:hover:bg-white/5 rounded-b-2xl w-full"
            @click="open = !open"
        >
            <span class="inline-flex items-center gap-2 font-medium text-ink-700 dark:text-ink-200">
                <svg class="w-4 h-4 shrink-0 text-ink-400 transition-transform duration-200" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
                <span>{{ $resourceCount }} {{ Str::plural('Resource', $resourceCount) }}</span>
            </span>
        </button>

        <div
            id="project-services-none"
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="border-t border-ink-100/90 dark:border-ink-800/70 p-5 space-y-4"
        >
            @if($groupServices->isNotEmpty())
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                    @foreach ($groupServices as $service)
                        @include('customer.services.partials.service-card', [
                            'service' => $service,
                            'allProjects' => $projects,
                            'nestedContainers' => [],
                        ])
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if($isProject)
        <div x-show="showRenameProject" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink-950/60 backdrop-blur-sm" @keydown.escape.window="showRenameProject = false">
            <div class="ui-card w-full max-w-md p-6" @click.outside="showRenameProject = false">
                <h3 class="font-display text-lg font-bold text-ink-950 dark:text-white">Rename project</h3>
                <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">Your own label — billing and deployments are unchanged.</p>
                <form method="POST" action="{{ route('customer.projects.rename', $project) }}" class="mt-4 space-y-4">
                    @csrf
                    @method('PATCH')
                    <input type="text" name="name" x-model="renameProjectName" required minlength="2" maxlength="100" class="w-full px-4 py-2.5">
                    <div class="flex gap-2">
                        <button type="button" @click="showRenameProject = false" class="btn-secondary flex-1 btn-sm">Cancel</button>
                        <button type="submit" class="btn-primary flex-1 btn-sm">Save</button>
                    </div>
                </form>
            </div>
        </div>

        @if($canRemoveProject)
            <div x-show="showRemoveProject" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink-950/60 backdrop-blur-sm" @keydown.escape.window="showRemoveProject = false">
                <div class="ui-card w-full max-w-md p-6" @click.outside="showRemoveProject = false">
                    <h3 class="font-display text-lg font-bold text-ink-950 dark:text-white">Remove project</h3>
                    <p class="mt-1 text-sm text-ink-600 dark:text-ink-300">
                        This permanently deletes every Application Hosting site in <strong>{{ $project->name }}</strong>, including containers and files. This cannot be undone. Email Hosting is not deleted this way.
                    </p>
                    <form method="POST" action="{{ route('customer.projects.destroy', $project) }}" class="mt-4 space-y-4">
                        @csrf
                        @method('DELETE')
                        <label class="block text-sm font-medium text-ink-700 dark:text-ink-200">
                            Type <span class="font-mono">{{ $project->name }}</span> to confirm
                            <input type="text" name="confirm_name" x-model="confirmName" required autocomplete="off" class="mt-1.5 w-full px-4 py-2.5">
                        </label>
                        <label class="flex items-start gap-2 text-sm text-ink-700 dark:text-ink-200">
                            <input type="checkbox" name="confirm" value="1" required class="mt-1 rounded">
                            <span>I understand these Application Hosting sites and their files will be permanently deleted.</span>
                        </label>
                        <div class="flex gap-2">
                            <button type="button" @click="showRemoveProject = false" class="btn-secondary flex-1 btn-sm">Keep project</button>
                            <button
                                type="submit"
                                class="btn btn-sm flex-1 bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-500 disabled:opacity-40"
                                :disabled="confirmName !== @js($project->name)"
                            >Delete sites</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endif
</article>
