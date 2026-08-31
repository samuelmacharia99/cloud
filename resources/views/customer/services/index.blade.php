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
    <x-page-header title="My Services" description="Projects share one plan. Open a project to deploy more services without a second bill — usage above the plan is metered.">
        <x-slot:actions>
            <button type="button" @click="showNewProject = true" class="btn-secondary">New project</button>
            <a href="{{ route('customer.email-hosting') }}" class="btn-secondary">Email Hosting</a>
            <a href="{{ $deployUrl }}" class="btn-primary">Deploy</a>
        </x-slot:actions>
    </x-page-header>

    <div x-show="showNewProject" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="showNewProject = false">
        <div class="w-full max-w-md rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6" @click.outside="showNewProject = false">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">New project</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Give it a name, then open the card to choose a plan or deploy a service.</p>
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
        Drop onto a project card (or “No project”) to move.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @foreach ($serviceGroups as $group)
            @include('customer.services.partials.project-card', [
                'group' => $group,
                'projects' => $projects,
            ])
        @endforeach

        <button
            type="button"
            @click="showNewProject = true"
            class="min-h-[11.5rem] rounded-2xl border border-dashed border-slate-300 dark:border-white/20 bg-transparent text-slate-500 dark:text-slate-400 hover:border-slate-400 dark:hover:border-white/40 hover:text-slate-700 dark:hover:text-slate-200 transition flex flex-col items-center justify-center gap-2"
        >
            <span class="text-2xl leading-none" aria-hidden="true">+</span>
            <span class="text-sm font-medium">New project</span>
        </button>
    </div>

    @if ($services->isEmpty() && $projects->isEmpty())
        <p class="text-sm text-slate-500 dark:text-slate-400">
            Create a project, then deploy an Application Hosting plan into it.
            <a href="{{ $deployUrl }}" class="font-medium text-brand-600 dark:text-brand-400 hover:underline">Deploy your first app</a>
        </p>
    @endif
</div>
@endsection
