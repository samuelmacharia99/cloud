@extends('layouts.admin')

@section('title', 'Container Templates')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Container Templates</p>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Container Templates</h1>
            <p class="mt-1 text-slate-600 dark:text-slate-400">Docker images and compose definitions used for Application Hosting stacks.</p>
        </div>
        <a href="{{ route('admin.container-templates.create') }}" class="inline-flex items-center justify-center px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
            Create Template
        </a>
    </div>

    @if ($message = Session::get('success'))
        <div class="rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950/40 px-4 py-3 text-green-800 dark:text-green-200">
            {{ $message }}
        </div>
    @endif

    @if ($message = Session::get('error'))
        <div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 px-4 py-3 text-red-800 dark:text-red-200">
            {{ $message }}
        </div>
    @endif

    <div class="grid gap-4">
        @forelse ($templates as $template)
            <article class="ui-card p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="mb-2 flex flex-wrap items-center gap-2">
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-50 dark:bg-slate-800">
                                <x-tech-stack-icon :slug="$template->slug" class="w-7 h-7" />
                            </span>
                            <h2 class="text-xl font-semibold text-slate-900 dark:text-white">{{ $template->name }}</h2>
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $template->is_active ? 'bg-green-100 text-green-800 dark:bg-green-950/60 dark:text-green-200' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' }}">
                                {{ $template->is_active ? 'Active' : 'Inactive' }}
                            </span>
                            <span class="inline-flex rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold capitalize text-blue-800 dark:bg-blue-950/60 dark:text-blue-200">
                                {{ $template->category }}
                            </span>
                        </div>

                        @if($template->description)
                            <p class="mb-4 text-slate-600 dark:text-slate-300">{{ $template->description }}</p>
                        @endif

                        <div class="mb-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Docker Image</p>
                                <p class="mt-0.5 font-mono text-sm text-slate-900 dark:text-white break-all">{{ $template->docker_image }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Port</p>
                                <p class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $template->default_port }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">CPU Cores</p>
                                <p class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $template->required_cpu_cores }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">RAM / Storage</p>
                                <p class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $template->required_ram_mb }}MB / {{ $template->required_storage_gb }}GB</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 text-sm text-slate-600 dark:text-slate-300">
                            @if ($template->environment_variables)
                                <p class="font-medium">{{ count($template->environment_variables) }} environment variables</p>
                            @endif
                            @if ($template->volume_paths)
                                <p class="font-medium">{{ count($template->volume_paths) }} volumes</p>
                            @endif
                        </div>

                        @if ($template->versions && count($template->versions) > 0)
                            <div class="mt-4">
                                <p class="mb-2 text-sm font-medium text-slate-500 dark:text-slate-400">Available versions</p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($template->versions as $version)
                                        <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-800 dark:bg-blue-950/60 dark:text-blue-200">{{ $version }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                            Using <strong class="text-slate-700 dark:text-slate-200">{{ $template->products()->count() }}</strong> products
                        </p>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <a href="{{ route('admin.container-templates.show', $template) }}" class="rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                            View
                        </a>
                        <a href="{{ route('admin.container-templates.edit', $template) }}" class="rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                            Edit
                        </a>
                        <form method="POST" action="{{ route('admin.container-templates.destroy', $template) }}" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-lg bg-red-100 px-3 py-2 text-sm font-medium text-red-700 transition hover:bg-red-200 dark:bg-red-950/50 dark:text-red-300 dark:hover:bg-red-950/70">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
            </article>
        @empty
            <div class="ui-card p-8 text-center">
                <p class="text-slate-500 dark:text-slate-400">No container templates found.</p>
                <a href="{{ route('admin.container-templates.create') }}" class="mt-2 inline-block font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                    Create your first template
                </a>
            </div>
        @endforelse
    </div>
</div>
@endsection
