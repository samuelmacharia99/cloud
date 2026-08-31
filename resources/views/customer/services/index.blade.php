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
    <x-page-header title="My Services" description="A project is billed once. Open a project to see its services and deploy more on the same plan — only usage above the plan is metered.">
        <x-slot:actions>
            <button type="button" @click="showNewProject = true" class="btn-secondary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 5v14m-7-7h14"/>
                </svg>
                New project
            </button>
            <a href="{{ route('customer.email-hosting') }}" class="btn-secondary">Email Hosting</a>
            <a href="{{ $deployUrl }}" class="btn-primary">Deploy</a>
        </x-slot:actions>
    </x-page-header>

    <div
        x-show="draggingId"
        x-cloak
        x-transition
        class="flex items-center gap-2.5 rounded-xl border border-brand-300/70 dark:border-brand-800/70 bg-brand-50/80 dark:bg-brand-950/40 px-4 py-2.5 text-sm font-medium text-brand-800 dark:text-brand-200"
    >
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
        </svg>
        Drop onto a project card — or onto “No project” to ungroup.
    </div>

    @if (empty($serviceGroups))
        <div class="ui-card px-6 py-12 text-center">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-400 to-brand-600 text-ink-950 ring-1 ring-brand-300/50 shadow-glow" aria-hidden="true">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M21 7.5l-9-4.5-9 4.5m18 0l-9 4.5m9-4.5v9l-9 4.5m0-9L3 7.5m9 4.5v9m-9-13.5v9l9 4.5"/>
                </svg>
            </span>
            <h2 class="mt-5 font-display text-xl font-bold text-ink-950 dark:text-white">Nothing deployed yet</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-ink-500 dark:text-ink-400">
                Create a project, pick an Application Hosting plan, then keep adding services to it without a second bill.
            </p>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
                <button type="button" @click="showNewProject = true" class="btn-secondary">New project</button>
                <a href="{{ $deployUrl }}" class="btn-primary">Deploy your first app</a>
            </div>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach ($serviceGroups as $group)
                @include('customer.services.partials.project-card', [
                    'group' => $group,
                    'projects' => $projects,
                ])
            @endforeach

            <button
                type="button"
                @click="showNewProject = true"
                class="group flex min-h-[13rem] flex-col items-center justify-center gap-2.5 rounded-2xl border border-dashed border-ink-300/90 dark:border-ink-700/70 px-5 py-8 text-center transition-all duration-200 hover:border-brand-400/80 hover:bg-brand-50/40 dark:hover:border-brand-600/60 dark:hover:bg-brand-950/20"
            >
                <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-ink-200 dark:border-ink-700 bg-white/80 dark:bg-ink-900/60 text-ink-500 dark:text-ink-300 transition-colors group-hover:border-brand-300 group-hover:text-brand-600 dark:group-hover:text-brand-300" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 5v14m-7-7h14"/>
                    </svg>
                </span>
                <span class="text-sm font-semibold text-ink-800 dark:text-ink-100">New project</span>
                <span class="max-w-[15rem] text-xs text-ink-500 dark:text-ink-400">Group services so they share one plan and one invoice</span>
            </button>
        </div>
    @endif

    <div x-show="showNewProject" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink-950/60 backdrop-blur-sm" @keydown.escape.window="showNewProject = false">
        <div class="ui-card w-full max-w-md p-6" @click.outside="showNewProject = false">
            <h3 class="font-display text-lg font-bold text-ink-950 dark:text-white">New project</h3>
            <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">Name it, then choose a plan or deploy a service into it.</p>
            <form method="POST" action="{{ route('customer.projects.store') }}" class="mt-4 space-y-4">
                @csrf
                <label class="block text-sm font-medium text-ink-700 dark:text-ink-200">
                    Project name
                    <input type="text" name="name" x-model="newProjectName" required minlength="2" maxlength="100" class="mt-1.5 w-full px-4 py-2.5">
                </label>
                <div class="flex gap-2">
                    <button type="button" @click="showNewProject = false" class="btn-secondary flex-1 btn-sm">Cancel</button>
                    <button type="submit" class="btn-primary flex-1 btn-sm">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
