@extends('layouts.admin')

@section('title', $containerTemplate->name)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.container-templates.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Container Templates</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="font-medium text-slate-600 dark:text-slate-400">{{ $containerTemplate->name }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <div class="flex items-center gap-3">
                <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-slate-50 dark:bg-slate-800">
                    <x-tech-stack-icon :slug="$containerTemplate->slug" class="w-8 h-8" />
                </span>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $containerTemplate->name }}</h1>
            </div>
            @if($containerTemplate->description)
                <p class="mt-2 text-slate-600 dark:text-slate-300">{{ $containerTemplate->description }}</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.container-templates.edit', $containerTemplate) }}" class="inline-flex items-center px-5 py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition">
                Edit Template
            </a>
            <a href="{{ route('admin.container-templates.index') }}" class="inline-flex items-center px-5 py-2.5 bg-slate-600 text-white rounded-lg font-medium hover:bg-slate-700 transition">
                Back to Templates
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="ui-card p-6">
                <h2 class="mb-4 text-xl font-bold text-slate-900 dark:text-white">Template Information</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Slug</p>
                        <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ $containerTemplate->slug }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Category</p>
                        <p class="mt-1">
                            <span class="inline-block rounded-full bg-blue-100 px-3 py-1 text-sm font-semibold capitalize text-blue-800 dark:bg-blue-950/60 dark:text-blue-200">{{ $containerTemplate->category }}</span>
                        </p>
                    </div>
                    <div class="sm:col-span-2">
                        <p class="text-sm text-slate-500 dark:text-slate-400">Docker Image</p>
                        <p class="mt-1 rounded-lg bg-slate-100 p-2 font-mono text-sm text-slate-900 dark:bg-slate-800 dark:text-slate-100 break-all">{{ $containerTemplate->docker_image }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Default Port</p>
                        <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ $containerTemplate->default_port }}</p>
                    </div>
                </div>
            </section>

            <section class="ui-card p-6">
                <h2 class="mb-4 text-xl font-bold text-slate-900 dark:text-white">Resource Requirements</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                        <p class="text-sm text-slate-500 dark:text-slate-400">CPU Cores</p>
                        <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $containerTemplate->required_cpu_cores }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                        <p class="text-sm text-slate-500 dark:text-slate-400">RAM</p>
                        <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ number_format($containerTemplate->required_ram_mb) }} MB</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                        <p class="text-sm text-slate-500 dark:text-slate-400">Storage</p>
                        <p class="text-2xl font-bold text-orange-600 dark:text-orange-400">{{ $containerTemplate->required_storage_gb }} GB</p>
                    </div>
                </div>
            </section>

            @if($containerTemplate->environment_variables && count($containerTemplate->environment_variables) > 0)
            <section class="ui-card overflow-hidden">
                <div class="border-b border-slate-200 px-6 py-4 dark:border-slate-700">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white">Environment Variables</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-slate-900 dark:text-white">Key</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-900 dark:text-white">Default</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-900 dark:text-white">Type</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-900 dark:text-white">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            @foreach($containerTemplate->environment_variables as $var)
                            <tr class="text-slate-700 dark:text-slate-200">
                                <td class="px-4 py-3 font-mono text-xs">{{ $var['key'] ?? '' }}</td>
                                <td class="px-4 py-3 font-mono text-xs bg-slate-50 dark:bg-slate-900/50">{{ $var['default'] ?? '(none)' }}</td>
                                <td class="px-4 py-3">
                                    @if(($var['required'] ?? false))
                                        <span class="inline-block rounded px-2 py-1 text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-200">Required</span>
                                    @elseif(($var['secret'] ?? false))
                                        <span class="inline-block rounded px-2 py-1 text-xs font-semibold bg-orange-100 text-orange-800 dark:bg-orange-950/60 dark:text-orange-200">Secret</span>
                                    @else
                                        <span class="inline-block rounded px-2 py-1 text-xs font-semibold bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">Optional</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $var['description'] ?? '-' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
            @endif

            @if($containerTemplate->volume_paths && count($containerTemplate->volume_paths) > 0)
            <section class="ui-card p-6">
                <h2 class="mb-4 text-xl font-bold text-slate-900 dark:text-white">Volume Mounts</h2>
                <div class="space-y-2">
                    @foreach($containerTemplate->volume_paths as $name => $path)
                    <div class="flex items-center justify-between rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <div>
                            <p class="font-semibold text-slate-900 dark:text-white">{{ $name }}</p>
                            <p class="text-sm font-mono text-slate-600 dark:text-slate-300">{{ $path }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </section>
            @endif

            @if($containerTemplate->setup_commands && count($containerTemplate->setup_commands) > 0)
            <section class="ui-card p-6">
                <h2 class="mb-4 text-xl font-bold text-slate-900 dark:text-white">Setup Commands</h2>
                <div class="space-y-2 overflow-x-auto rounded-lg bg-slate-900 p-4 font-mono text-sm text-slate-100 dark:bg-black/60">
                    @foreach($containerTemplate->setup_commands as $i => $command)
                    <div>{{ $i + 1 }}. {{ $command }}</div>
                    @endforeach
                </div>
            </section>
            @endif
        </div>

        <aside class="space-y-6">
            <section class="ui-card p-6">
                <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-white">Associated Products</h2>
                @if($containerTemplate->products->count() > 0)
                    <ul class="space-y-3">
                        @foreach($containerTemplate->products as $product)
                        <li>
                            <a href="{{ route('admin.products.show', $product) }}" class="font-medium text-blue-600 hover:underline dark:text-blue-400">
                                {{ $product->name }}
                            </a>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ ucfirst($product->type) }}</p>
                        </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-slate-600 dark:text-slate-400">No products yet</p>
                @endif
                <a href="{{ route('admin.products.create', ['template' => $containerTemplate->id]) }}" class="mt-4 inline-block rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 transition">
                    Create Product
                </a>
            </section>

            <section class="ui-card p-6">
                <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-white">Active Deployments</h2>
                <div class="py-2 text-center">
                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400">{{ $deploymentCount }}</p>
                    <p class="text-slate-600 dark:text-slate-400">services running</p>
                </div>
            </section>

            @if($containerTemplate->versions && count($containerTemplate->versions) > 0)
            <section class="ui-card p-6">
                <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-white">Versions</h2>
                <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                    @foreach($containerTemplate->versions as $version)
                    <li class="flex items-center justify-between py-2">
                        <span class="font-semibold text-slate-900 dark:text-white">{{ $version }}</span>
                        <span class="text-sm text-slate-500 dark:text-slate-400">Available</span>
                    </li>
                    @endforeach
                </ul>
            </section>
            @endif
        </aside>
    </div>
</div>
@endsection
