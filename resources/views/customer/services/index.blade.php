@extends('layouts.customer')

@section('title', 'My Services')

@section('content')
<div class="space-y-6">
    <x-page-header title="My Services" description="Manage your active subscriptions, hosting, and containers.">
        <x-slot:actions>
            <a href="{{ route('customer.email-hosting') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 text-sm font-medium transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                Email Hosting
            </a>
            <a href="{{ auth()->user()->reseller_id ? route('customer.catalog.index') : route('customer.select-techstack') }}" class="btn-primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Deploy new service
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($services->count() > 0)
        <div class="space-y-6">
            @foreach ($serviceGroups as $group)
                @if (($group['type'] ?? '') === 'project')
                    @php
                        $project = $group['project'];
                        $projectServices = $group['services'];
                        $containers = $group['containers'] ?? [];
                        $primary = $projectServices->first(fn ($s) => $s->isContainerHosting()) ?? $projectServices->first();
                        $primaryManageUrl = $primary->unpaidActivationInvoice()
                            ? route('customer.payment.select-method', $primary->unpaidActivationInvoice())
                            : route('customer.services.show', $primary);
                    @endphp
                    <section
                        class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50/60 dark:bg-slate-900/40 overflow-hidden"
                        x-data="{ showRenameProjectModal: false, renameProjectName: @js($project->name) }"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-5 py-3.5 border-b border-slate-200/80 dark:border-slate-700/80 bg-white/70 dark:bg-slate-800/50">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <h2 class="text-base font-semibold text-slate-900 dark:text-white truncate">{{ $project->name }}</h2>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        {{ $projectServices->count() }} {{ Str::plural('service', $projectServices->count()) }}
                                        @if(count($containers) > 0)
                                            <span class="text-slate-400 dark:text-slate-500">·</span>
                                            {{ count($containers) }} {{ Str::plural('container', count($containers)) }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                @click="showRenameProjectModal = true; renameProjectName = @js($project->name)"
                                class="btn-secondary btn-sm"
                            >
                                Rename project
                            </button>
                        </div>

                        @if(count($containers) >= 2)
                            <div class="px-4 sm:px-5 py-3 border-b border-slate-200/70 dark:border-slate-700/70 flex flex-wrap gap-2">
                                @foreach ($containers as $containerLabel)
                                    <a
                                        href="{{ $primaryManageUrl }}"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 px-2.5 py-1 text-xs font-medium text-slate-700 dark:text-slate-200 hover:border-brand-300 dark:hover:border-brand-600 hover:text-brand-700 dark:hover:text-brand-300 transition"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                        {{ $containerLabel }}
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        <div class="p-4 sm:p-5">
                            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-6">
                                @foreach ($projectServices as $service)
                                    @include('customer.services.partials.service-card', ['service' => $service])
                                @endforeach
                            </div>
                        </div>

                        <div
                            x-show="showRenameProjectModal"
                            x-cloak
                            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                            @keydown.escape.window="showRenameProjectModal = false"
                        >
                            <div
                                class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6"
                                @click.outside="showRenameProjectModal = false"
                            >
                                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Rename project</h3>
                                <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
                                    This folder groups related services and containers. Renaming it does not change plans or billing.
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
                    </section>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-6">
                        @foreach ($group['services'] as $service)
                            @include('customer.services.partials.service-card', ['service' => $service])
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>
    @else
        <x-empty-state
            title="No services yet"
            description="Deploy application hosting, or order Email Hosting as its own plan and bundle them in the cart."
            action-label="Deploy your first app"
            action-href="{{ auth()->user()->reseller_id ? route('customer.catalog.index') : route('customer.select-techstack') }}"
        />
        <div class="text-center -mt-2">
            <a href="{{ route('customer.email-hosting') }}" class="text-sm font-medium text-teal-700 dark:text-teal-300 hover:underline">
                Or order Email Hosting
            </a>
        </div>
    @endif
</div>
@endsection
