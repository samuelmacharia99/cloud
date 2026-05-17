@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="mb-8">
        <div class="flex justify-between items-center mb-4">
            <h1 class="text-4xl font-bold text-gray-900">{{ $containerTemplate->name }}</h1>
            <div class="space-x-2">
                <a href="{{ route('admin.container-templates.edit', $containerTemplate) }}" class="inline-block px-6 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                    Edit Template
                </a>
                <a href="{{ route('admin.container-templates.index') }}" class="inline-block px-6 py-2 bg-gray-600 text-white rounded hover:bg-gray-700">
                    Back to Templates
                </a>
            </div>
        </div>
        <p class="text-gray-600">{{ $containerTemplate->description }}</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Main Content -->
        <div class="lg:col-span-2">
            <!-- Basic Information -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Template Information</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm text-gray-600">Slug</p>
                        <p class="text-lg font-semibold">{{ $containerTemplate->slug }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Category</p>
                        <p class="text-lg font-semibold"><span class="inline-block px-3 py-1 bg-blue-100 text-blue-800 rounded">{{ ucfirst($containerTemplate->category) }}</span></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Docker Image</p>
                        <p class="text-lg font-mono bg-gray-100 p-2 rounded">{{ $containerTemplate->docker_image }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Default Port</p>
                        <p class="text-lg font-semibold">{{ $containerTemplate->default_port }}</p>
                    </div>
                </div>
            </div>

            <!-- Resource Requirements -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Resource Requirements</h2>
                <div class="grid grid-cols-3 gap-4">
                    <div class="border rounded p-4">
                        <p class="text-sm text-gray-600">CPU Cores</p>
                        <p class="text-2xl font-bold text-blue-600">{{ $containerTemplate->required_cpu_cores }}</p>
                    </div>
                    <div class="border rounded p-4">
                        <p class="text-sm text-gray-600">RAM</p>
                        <p class="text-2xl font-bold text-green-600">{{ number_format($containerTemplate->required_ram_mb) }} MB</p>
                    </div>
                    <div class="border rounded p-4">
                        <p class="text-sm text-gray-600">Storage</p>
                        <p class="text-2xl font-bold text-orange-600">{{ $containerTemplate->required_storage_gb }} GB</p>
                    </div>
                </div>
            </div>

            <!-- Environment Variables -->
            @if($containerTemplate->environment_variables && count($containerTemplate->environment_variables) > 0)
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Environment Variables</h2>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-4 py-2 text-left">Key</th>
                                <th class="px-4 py-2 text-left">Default</th>
                                <th class="px-4 py-2 text-left">Type</th>
                                <th class="px-4 py-2 text-left">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($containerTemplate->environment_variables as $var)
                            <tr class="border-t">
                                <td class="px-4 py-2 font-mono text-sm">{{ $var['key'] ?? '' }}</td>
                                <td class="px-4 py-2 font-mono text-sm bg-gray-50">{{ $var['default'] ?? '(none)' }}</td>
                                <td class="px-4 py-2">
                                    @if(($var['required'] ?? false))
                                        <span class="inline-block px-2 py-1 bg-red-100 text-red-800 text-xs rounded">Required</span>
                                    @elseif(($var['secret'] ?? false))
                                        <span class="inline-block px-2 py-1 bg-orange-100 text-orange-800 text-xs rounded">Secret</span>
                                    @else
                                        <span class="inline-block px-2 py-1 bg-gray-100 text-gray-800 text-xs rounded">Optional</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm">{{ $var['description'] ?? '-' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            <!-- Volume Paths -->
            @if($containerTemplate->volume_paths && count($containerTemplate->volume_paths) > 0)
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Volume Mounts</h2>
                <div class="space-y-2">
                    @foreach($containerTemplate->volume_paths as $name => $path)
                    <div class="border rounded p-3 flex justify-between items-center">
                        <div>
                            <p class="font-semibold">{{ $name }}</p>
                            <p class="text-sm text-gray-600 font-mono">{{ $path }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            <!-- Setup Commands -->
            @if($containerTemplate->setup_commands && count($containerTemplate->setup_commands) > 0)
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Setup Commands</h2>
                <div class="bg-gray-900 text-gray-100 p-4 rounded font-mono text-sm overflow-x-auto space-y-2">
                    @foreach($containerTemplate->setup_commands as $i => $command)
                    <div>{{ $i + 1 }}. {{ $command }}</div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        <!-- Sidebar -->
        <div class="lg:col-span-1">
            <!-- Associated Products -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Associated Products</h2>
                @if($containerTemplate->products->count() > 0)
                    <ul class="space-y-2">
                        @foreach($containerTemplate->products as $product)
                        <li>
                            <a href="{{ route('admin.products.show', $product) }}" class="text-blue-600 hover:underline">
                                {{ $product->name }}
                            </a>
                            <p class="text-sm text-gray-600">{{ ucfirst($product->type) }}</p>
                        </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-gray-600">No products yet</p>
                @endif
                <a href="{{ route('admin.products.create', ['template' => $containerTemplate->id]) }}" class="inline-block mt-4 px-4 py-2 bg-green-600 text-white rounded text-sm hover:bg-green-700">
                    Create Product
                </a>
            </div>

            <!-- Active Deployments -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Active Deployments</h2>
                <div class="text-center py-4">
                    <p class="text-3xl font-bold text-blue-600">{{ $deploymentCount }}</p>
                    <p class="text-gray-600">services running</p>
                </div>
            </div>

            <!-- Template Versions -->
            @if($containerTemplate->versions && count($containerTemplate->versions) > 0)
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Versions</h2>
                <ul class="space-y-2">
                    @foreach($containerTemplate->versions as $version)
                    <li class="flex justify-between items-center py-2">
                        <span class="font-semibold">{{ $version }}</span>
                        <span class="text-sm text-gray-600">Available</span>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
