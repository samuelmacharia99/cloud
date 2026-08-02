@extends('layouts.customer')

@section('title', 'My Services')

@section('content')
@php
    $deployUrl = auth()->user()->reseller_id
        ? route('customer.catalog.index')
        : route('customer.select-techstack');
    $selectedProjectModel = ($selectedProject ?? 'all') !== 'all' && ($selectedProject ?? 'all') !== 'ungrouped'
        ? ($projects ?? collect())->firstWhere('id', (int) $selectedProject)
        : null;
@endphp
<div class="space-y-5">
    <x-page-header title="Resources" description="Projects group related services and containers — console-style, like a cloud workspace.">
        <x-slot:actions>
            <a href="{{ route('customer.email-hosting') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 text-sm font-medium transition">
                Email Hosting
            </a>
            <a href="{{ $deployUrl }}" class="btn-primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Deploy
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($services->count() > 0)
        {{-- Project switcher (Hetzner-style context bar) --}}
        <div
            class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 overflow-visible"
            x-data="{
                projectOpen: false,
                showRenameProjectModal: false,
                renameProjectName: @js($selectedProjectModel?->name ?? ''),
                selectedProjectId: @js($selectedProjectModel?->id)
            }"
        >
            <div class="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-3 border-b border-slate-200 dark:border-slate-700">
                <div class="relative min-w-0 flex-1 sm:flex-none sm:min-w-[16rem]">
                    <button
                        type="button"
                        @click="projectOpen = !projectOpen"
                        class="w-full sm:w-auto inline-flex items-center justify-between gap-3 rounded-lg border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-800/80 px-3 py-2 text-sm font-medium text-slate-900 dark:text-white hover:border-slate-300 dark:hover:border-slate-500 transition"
                    >
                        <span class="inline-flex items-center gap-2 min-w-0">
                            <svg class="w-4 h-4 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
                            </svg>
                            <span class="truncate">
                                @if(($selectedProject ?? 'all') === 'all')
                                    All projects
                                @elseif(($selectedProject ?? 'all') === 'ungrouped')
                                    Ungrouped resources
                                @else
                                    {{ $selectedProjectModel?->name ?? 'Project' }}
                                @endif
                            </span>
                        </span>
                        <svg class="w-4 h-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>

                    <div
                        x-show="projectOpen"
                        @click.outside="projectOpen = false"
                        x-cloak
                        class="absolute left-0 mt-1 w-72 max-w-[calc(100vw-2rem)] rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-xl z-30 overflow-hidden"
                    >
                        <div class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500 border-b border-slate-100 dark:border-slate-800">
                            Switch project
                        </div>
                        <a
                            href="{{ route('customer.services.index', ['project' => 'all']) }}"
                            class="flex items-center gap-2 px-3 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800 {{ ($selectedProject ?? 'all') === 'all' ? 'bg-brand-50/80 dark:bg-brand-950/30 text-brand-700 dark:text-brand-300 font-medium' : 'text-slate-700 dark:text-slate-200' }}"
                        >
                            All projects
                        </a>
                        @foreach ($projects as $projectOption)
                            <a
                                href="{{ route('customer.services.index', ['project' => $projectOption->id]) }}"
                                class="flex items-center gap-2 px-3 py-2.5 text-sm border-t border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800 {{ (string) ($selectedProject ?? '') === (string) $projectOption->id ? 'bg-brand-50/80 dark:bg-brand-950/30 text-brand-700 dark:text-brand-300 font-medium' : 'text-slate-700 dark:text-slate-200' }}"
                            >
                                <svg class="w-3.5 h-3.5 shrink-0 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg>
                                <span class="truncate">{{ $projectOption->name }}</span>
                            </a>
                        @endforeach
                        @if($services->contains(fn ($s) => ! $s->project_id))
                            <a
                                href="{{ route('customer.services.index', ['project' => 'ungrouped']) }}"
                                class="flex items-center gap-2 px-3 py-2.5 text-sm border-t border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800 {{ ($selectedProject ?? '') === 'ungrouped' ? 'bg-brand-50/80 dark:bg-brand-950/30 text-brand-700 dark:text-brand-300 font-medium' : 'text-slate-700 dark:text-slate-200' }}"
                            >
                                Ungrouped resources
                            </a>
                        @endif
                    </div>
                </div>

                @if($selectedProjectModel)
                    <button
                        type="button"
                        @click="showRenameProjectModal = true; renameProjectName = @js($selectedProjectModel->name); selectedProjectId = {{ $selectedProjectModel->id }}"
                        class="btn-secondary btn-sm"
                    >
                        Rename project
                    </button>
                @endif

                <div class="ml-auto text-xs text-slate-500 dark:text-slate-400 tabular-nums">
                    {{ collect($serviceGroups)->sum(fn ($g) => $g['services']->count()) }}
                    {{ Str::plural('resource', collect($serviceGroups)->sum(fn ($g) => $g['services']->count())) }}
                </div>
            </div>

            @if($selectedProjectModel)
                <div
                    x-show="showRenameProjectModal"
                    x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                    @keydown.escape.window="showRenameProjectModal = false"
                >
                    <div
                        class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6"
                        @click.outside="showRenameProjectModal = false"
                    >
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Rename project</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
                            Projects organize related services and containers. Renaming does not change plans or billing.
                        </p>
                        <form method="POST" action="{{ route('customer.projects.rename', $selectedProjectModel) }}" class="space-y-4">
                            @csrf
                            @method('PATCH')
                            <div>
                                <label for="rename-project-selected" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Project name</label>
                                <input
                                    id="rename-project-selected"
                                    type="text"
                                    name="name"
                                    x-model="renameProjectName"
                                    required
                                    minlength="2"
                                    maxlength="100"
                                    class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-brand-500"
                                >
                            </div>
                            <div class="flex gap-2">
                                <button type="button" @click="showRenameProjectModal = false" class="btn-secondary flex-1 btn-sm">Cancel</button>
                                <button type="submit" class="btn-primary flex-1 btn-sm">Save</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

            <div class="divide-y divide-slate-200 dark:divide-slate-700">
                @forelse ($serviceGroups as $group)
                    @php
                        $isProject = ($group['type'] ?? '') === 'project';
                        $project = $isProject ? $group['project'] : null;
                        $groupServices = $group['services'];
                        $containers = $group['containers'] ?? [];
                    @endphp

                    <section
                        @if($isProject)
                            x-data="{ showRenameProjectModal: false, renameProjectName: @js($project->name) }"
                        @endif
                    >
                        @if($isProject && ($selectedProject ?? 'all') === 'all')
                            <div class="flex flex-wrap items-center justify-between gap-2 px-4 sm:px-5 py-2.5 bg-slate-50/80 dark:bg-slate-800/40">
                                <div class="flex items-center gap-2 min-w-0">
                                    <svg class="w-4 h-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg>
                                    <a
                                        href="{{ route('customer.services.index', ['project' => $project->id]) }}"
                                        class="text-sm font-semibold text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400 truncate"
                                    >
                                        {{ $project->name }}
                                    </a>
                                    <span class="text-xs text-slate-400 tabular-nums">
                                        {{ $groupServices->count() }}
                                        @if(count($containers) > 0)
                                            · {{ count($containers) }} containers
                                        @endif
                                    </span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        @click="showRenameProjectModal = true; renameProjectName = @js($project->name)"
                                        class="text-xs font-medium text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400"
                                    >
                                        Rename project
                                    </button>
                                    <a
                                        href="{{ route('customer.services.index', ['project' => $project->id]) }}"
                                        class="text-xs font-medium text-brand-600 dark:text-brand-400 hover:underline"
                                    >
                                        Open
                                    </a>
                                </div>
                            </div>

                            <div
                                x-show="showRenameProjectModal"
                                x-cloak
                                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                                @keydown.escape.window="showRenameProjectModal = false"
                            >
                                <div
                                    class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6"
                                    @click.outside="showRenameProjectModal = false"
                                >
                                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Rename project</h3>
                                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
                                        Projects organize related services and containers. Renaming does not change plans or billing.
                                    </p>
                                    <form method="POST" action="{{ route('customer.projects.rename', $project) }}" class="space-y-4">
                                        @csrf
                                        @method('PATCH')
                                        <div>
                                            <label for="rename-project-{{ $project->id }}" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Project name</label>
                                            <input
                                                id="rename-project-{{ $project->id }}"
                                                type="text"
                                                name="name"
                                                x-model="renameProjectName"
                                                required
                                                minlength="2"
                                                maxlength="100"
                                                class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-brand-500"
                                            >
                                        </div>
                                        <div class="flex gap-2">
                                            <button type="button" @click="showRenameProjectModal = false" class="btn-secondary flex-1 btn-sm">Cancel</button>
                                            <button type="submit" class="btn-primary flex-1 btn-sm">Save</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @elseif(! $isProject && ($selectedProject ?? 'all') === 'all' && ($projects ?? collect())->isNotEmpty())
                            <div class="px-4 sm:px-5 py-2.5 bg-slate-50/80 dark:bg-slate-800/40">
                                <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Ungrouped resources</p>
                            </div>
                        @endif

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[36rem] text-left">
                                <thead>
                                    <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                        <th class="py-2.5 pl-4 pr-3 sm:pl-5 font-semibold">Name</th>
                                        <th class="hidden sm:table-cell px-3 py-2.5 font-semibold">Status</th>
                                        <th class="hidden md:table-cell px-3 py-2.5 font-semibold">Product</th>
                                        <th class="hidden lg:table-cell px-3 py-2.5 font-semibold">Billing</th>
                                        <th class="hidden lg:table-cell px-3 py-2.5 font-semibold">Next due</th>
                                        <th class="py-2.5 pl-3 pr-4 sm:pr-5 font-semibold text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @php
                                        $primaryContainer = $isProject
                                            ? $groupServices->first(fn ($s) => $s->isContainerHosting())
                                            : null;
                                    @endphp
                                    @foreach ($groupServices as $service)
                                        @include('customer.services.partials.service-row', [
                                            'service' => $service,
                                            'nestedContainers' => (
                                                $primaryContainer
                                                && $service->id === $primaryContainer->id
                                                && count($containers) >= 2
                                            ) ? $containers : [],
                                        ])
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @empty
                    <div class="px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                        No resources in this project view.
                        <a href="{{ route('customer.services.index') }}" class="text-brand-600 dark:text-brand-400 hover:underline ml-1">Show all</a>
                    </div>
                @endforelse
            </div>
        </div>
    @else
        <x-empty-state
            title="No services yet"
            description="Deploy application hosting, or order Email Hosting as its own plan and bundle them in the cart."
            action-label="Deploy your first app"
            action-href="{{ $deployUrl }}"
        />
        <div class="text-center -mt-2">
            <a href="{{ route('customer.email-hosting') }}" class="text-sm font-medium text-teal-700 dark:text-teal-300 hover:underline">
                Or order Email Hosting
            </a>
        </div>
    @endif
</div>
@endsection
