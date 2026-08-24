@extends('layouts.customer')

@section('title', 'My Services')

@section('content')
@php
    $deployUrl = auth()->user()->reseller_id
        ? route('customer.catalog.index')
        : route('customer.select-techstack');
@endphp

<div
    class="space-y-6"
    @service-drag.window="
        if ($event.detail.phase === 'start') { draggingId = $event.detail.id; }
        else { draggingId = null; dropTarget = null; }
    "
    x-data="{
        showNewProject: false,
        newProjectName: 'Project',
        draggingId: null,
        dropTarget: null,
        saving: false,
        moveUrl: @js(url('/my/services/__ID__/project')),
        csrf: @js(csrf_token()),
        onDragOver(event, key) {
            if (! this.draggingId) return;
            this.dropTarget = key;
            event.dataTransfer.dropEffect = 'move';
        },
        onDragLeave(event, key) {
            if (this.dropTarget === key && ! event.currentTarget.contains(event.relatedTarget)) {
                this.dropTarget = null;
            }
        },
        async onDrop(event, projectId) {
            const serviceId = this.draggingId || event.dataTransfer.getData('text/plain');
            this.dropTarget = null;
            this.draggingId = null;
            if (! serviceId || this.saving) return;
            this.saving = true;
            try {
                const url = this.moveUrl.replace('__ID__', String(serviceId));
                const response = await fetch(url, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        project_id: projectId === null || projectId === 'none' ? null : projectId,
                    }),
                });
                if (! response.ok) throw new Error('Move failed');
                window.location.reload();
            } catch (e) {
                this.saving = false;
                alert('Could not move that service. Try Move on the card instead.');
            }
        },
    }"
>
    <x-page-header title="My Services" description="Drag a service card onto a project to group it. Laravel + Next stacks group automatically.">
        <x-slot:actions>
            <button type="button" @click="showNewProject = true" class="btn-secondary">New project</button>
            <a href="{{ route('customer.email-hosting') }}" class="btn-secondary">Email Hosting</a>
            <a href="{{ $deployUrl }}" class="btn-primary">Deploy</a>
        </x-slot:actions>
    </x-page-header>

    <div x-show="showNewProject" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="showNewProject = false">
        <div class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6" @click.outside="showNewProject = false">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">New project</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Then use Move on a service card to add it. Empty projects stay hidden until they have a service.</p>
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

    <p
        x-show="draggingId"
        x-cloak
        class="rounded-lg border border-brand-200 dark:border-brand-800 bg-brand-50 dark:bg-brand-950/40 px-4 py-2 text-sm text-brand-800 dark:text-brand-200"
    >
        Drop onto a project folder (or “No project”) to move.
    </p>

    @if ($services->count() > 0)
        <div class="space-y-5">
            @foreach ($serviceGroups as $group)
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
                @endphp

                <section
                    class="rounded-xl border bg-white dark:bg-slate-900 transition-colors"
                    :class="dropTarget === @js($dropKey) && draggingId
                        ? 'border-brand-400 dark:border-brand-500 ring-2 ring-brand-300/60 dark:ring-brand-700/60 bg-brand-50/40 dark:bg-brand-950/20'
                        : 'border-slate-200 dark:border-slate-700'"
                    @dragover.prevent="onDragOver($event, @js($dropKey))"
                    @dragleave="onDragLeave($event, @js($dropKey))"
                    @drop.prevent="onDrop($event, @js($isProject ? $project->id : null))"
                >
                    @if($isProject)
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 px-4 sm:px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40"
                            x-data="{ showRenameProject: false, showRemoveProject: false, renameProjectName: @js($project->name), confirmName: '' }"
                        >
                            <div class="min-w-0">
                                <h2 class="font-semibold text-slate-900 dark:text-white truncate">{{ $project->name }}</h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ $groupServices->count() }} {{ Str::plural('service', $groupServices->count()) }}
                                    @if(count($containers) >= 2)
                                        · {{ implode(', ', $containers) }}
                                    @endif
                                    @if(!empty($project->recipe_key))
                                        · billed as one project
                                    @endif
                                    · drop here
                                </p>
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <button type="button" @click="showRenameProject = true" class="text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400">
                                    Rename project
                                </button>
                                @if($canRemoveProject)
                                    <button type="button" @click="showRemoveProject = true" class="text-sm font-medium text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300">
                                        Remove project
                                    </button>
                                @endif
                            </div>

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
                        </div>
                    @else
                        <div class="px-4 sm:px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40">
                            <h2 class="font-semibold text-slate-900 dark:text-white">No project</h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Drag cards here to ungroup, or onto a project above.</p>
                        </div>
                    @endif

                    <div class="p-4 sm:p-5">
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-5 min-h-[6rem]">
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
