@extends('layouts.customer')

@section('title', 'My Services')

@section('content')
@php
    $deployUrl = auth()->user()->reseller_id
        ? route('customer.catalog.index')
        : route('customer.select-techstack');
@endphp
<div class="space-y-6" x-data="{ showNewProject: false, newProjectName: 'Project' }">
    <x-page-header title="My Services" description="Group related services under a project. Laravel + Next stacks stay together automatically.">
        <x-slot:actions>
            <button type="button" @click="showNewProject = true" class="btn-secondary">New project</button>
            <a href="{{ route('customer.email-hosting') }}" class="btn-secondary">Email Hosting</a>
            <a href="{{ $deployUrl }}" class="btn-primary">Deploy</a>
        </x-slot:actions>
    </x-page-header>

    {{-- New empty project --}}
    <div x-show="showNewProject" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="showNewProject = false">
        <div class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6" @click.outside="showNewProject = false">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">New project</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Then use Move to project on any service to add it.</p>
            <form method="POST" action="{{ route('customer.projects.store') }}" class="space-y-4">
                @csrf
                <input type="text" name="name" x-model="newProjectName" required minlength="2" maxlength="100" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800">
                <div class="flex gap-2">
                    <button type="button" @click="showNewProject = false" class="btn-secondary flex-1 btn-sm">Cancel</button>
                    <button type="submit" class="btn-primary flex-1 btn-sm">Create</button>
                </div>
            </form>
        </div>
    </div>

    @if ($services->count() > 0)
        <div class="space-y-4">
            @foreach ($serviceGroups as $group)
                @php
                    $isProject = ($group['type'] ?? '') === 'project';
                    $project = $isProject ? $group['project'] : null;
                    $groupServices = $group['services'];
                    $containers = $group['containers'] ?? [];
                    $primaryContainer = $isProject
                        ? $groupServices->first(fn ($s) => $s->isContainerHosting())
                        : null;
                @endphp

                <section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 overflow-hidden">
                    @if($isProject)
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 px-4 sm:px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40"
                            x-data="{ showRenameProject: false, renameProjectName: @js($project->name) }"
                        >
                            <div class="min-w-0">
                                <h2 class="font-semibold text-slate-900 dark:text-white truncate">{{ $project->name }}</h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ $groupServices->count() }} {{ Str::plural('service', $groupServices->count()) }}
                                    @if(count($containers) >= 2)
                                        · {{ implode(', ', $containers) }}
                                    @endif
                                </p>
                            </div>
                            <button type="button" @click="showRenameProject = true" class="text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400">
                                Rename project
                            </button>

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
                        </div>
                    @else
                        <div class="px-4 sm:px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40">
                            <h2 class="font-semibold text-slate-900 dark:text-white">No project</h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Use ··· → Move to project to group these.</p>
                        </div>
                    @endif

                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($groupServices as $service)
                            @include('customer.services.partials.service-row', [
                                'service' => $service,
                                'allProjects' => $projects,
                                'nestedContainers' => (
                                    $primaryContainer
                                    && $service->id === $primaryContainer->id
                                    && count($containers) >= 2
                                ) ? $containers : [],
                            ])
                        @endforeach
                    </ul>
                </section>
            @endforeach

            {{-- Empty projects (created but nothing assigned yet) --}}
            @php
                $usedProjectIds = collect($serviceGroups)
                    ->filter(fn ($g) => ($g['type'] ?? '') === 'project')
                    ->map(fn ($g) => $g['project']->id)
                    ->all();
                $emptyProjects = $projects->reject(fn ($p) => in_array($p->id, $usedProjectIds, true));
            @endphp
            @foreach ($emptyProjects as $emptyProject)
                <section
                    class="rounded-xl border border-dashed border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-4 sm:px-5 py-4"
                    x-data="{ showRenameProject: false, renameProjectName: @js($emptyProject->name) }"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="font-semibold text-slate-900 dark:text-white">{{ $emptyProject->name }}</h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Empty — move a service here from ··· → Move to project.</p>
                        </div>
                        <button type="button" @click="showRenameProject = true" class="text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-brand-600">Rename project</button>
                    </div>
                    <div x-show="showRenameProject" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="showRenameProject = false">
                        <div class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6" @click.outside="showRenameProject = false">
                            <form method="POST" action="{{ route('customer.projects.rename', $emptyProject) }}" class="space-y-4">
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
                </section>
            @endforeach
        </div>
    @else
        <x-empty-state
            title="No services yet"
            description="Deploy application hosting, or order Email Hosting as its own plan and bundle them in the cart."
            action-label="Deploy your first app"
            action-href="{{ $deployUrl }}"
        />
    @endif
</div>
@endsection
