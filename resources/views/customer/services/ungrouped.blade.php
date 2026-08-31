@extends('layouts.customer')

@section('title', 'Ungrouped services')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <a href="{{ route('customer.services.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 dark:text-ink-400 hover:text-brand-600 dark:hover:text-brand-300 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                My Services
            </a>
            <h1 class="mt-3 font-display text-2xl sm:text-3xl font-bold tracking-tight text-ink-950 dark:text-white">
                Ungrouped services
            </h1>
            <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">
                These are not on a project plan. Move one onto a project, or open it to manage it.
            </p>
        </div>
    </div>

    @if($services->isNotEmpty())
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
            @foreach ($services as $service)
                @include('customer.services.partials.service-card', [
                    'service' => $service,
                    'allProjects' => $projects,
                    'nestedContainers' => [],
                ])
            @endforeach
        </div>
    @else
        <div class="ui-card rounded-xl border border-dashed border-ink-300/80 dark:border-ink-700/70 px-6 py-10 text-center">
            <p class="text-sm font-medium text-ink-700 dark:text-ink-200">Nothing left ungrouped</p>
            <p class="mt-1 text-xs text-ink-500 dark:text-ink-400">Application Hosting services live on their project pages.</p>
            <a href="{{ route('customer.services.index') }}" class="btn-secondary btn-sm mt-4 inline-flex">Back to projects</a>
        </div>
    @endif
</div>
@endsection
