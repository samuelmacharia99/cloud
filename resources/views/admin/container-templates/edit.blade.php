@extends('layouts.admin')

@section('content')
<div class="container mx-auto px-4 py-8 max-w-4xl">
    <h1 class="text-3xl font-bold mb-8">Edit Container Template</h1>

    @if ($errors->any())
        <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
            <p class="font-semibold mb-2">Please fix the following errors:</p>
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.container-templates.update', $containerTemplate) }}" class="bg-white rounded-lg shadow p-6">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-2 gap-6 mb-6">
            <div>
                <label class="block text-sm font-semibold mb-2">Name *</label>
                <input type="text" name="name" value="{{ old('name', $containerTemplate->name) }}" class="w-full px-3 py-2 border rounded-lg @error('name') border-red-500 @enderror" required>
                @error('name')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-semibold mb-2">Slug *</label>
                <input type="text" name="slug" value="{{ old('slug', $containerTemplate->slug) }}" class="w-full px-3 py-2 border rounded-lg @error('slug') border-red-500 @enderror" required>
                @error('slug')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mb-6">
            <label class="block text-sm font-semibold mb-2">Description</label>
            <textarea name="description" rows="3" class="w-full px-3 py-2 border rounded-lg @error('description') border-red-500 @enderror">{{ old('description', $containerTemplate->description) }}</textarea>
            @error('description')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-2 gap-6 mb-6">
            <div>
                <label class="block text-sm font-semibold mb-2">Category *</label>
                <select name="category" class="w-full px-3 py-2 border rounded-lg @error('category') border-red-500 @enderror" required>
                    <option value="web" {{ old('category', $containerTemplate->category) === 'web' ? 'selected' : '' }}>Web</option>
                    <option value="database" {{ old('category', $containerTemplate->category) === 'database' ? 'selected' : '' }}>Database</option>
                    <option value="utility" {{ old('category', $containerTemplate->category) === 'utility' ? 'selected' : '' }}>Utility</option>
                    <option value="cache" {{ old('category', $containerTemplate->category) === 'cache' ? 'selected' : '' }}>Cache</option>
                </select>
                @error('category')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-semibold mb-2">Docker Image *</label>
                <input type="text" name="docker_image" value="{{ old('docker_image', $containerTemplate->docker_image) }}" class="w-full px-3 py-2 border rounded-lg @error('docker_image') border-red-500 @enderror" required>
                @error('docker_image')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="grid grid-cols-4 gap-4 mb-6">
            <div>
                <label class="block text-sm font-semibold mb-2">Port *</label>
                <input type="number" name="default_port" value="{{ old('default_port', $containerTemplate->default_port) }}" class="w-full px-3 py-2 border rounded-lg @error('default_port') border-red-500 @enderror" required>
                @error('default_port')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-semibold mb-2">RAM (MB) *</label>
                <input type="number" name="required_ram_mb" value="{{ old('required_ram_mb', $containerTemplate->required_ram_mb) }}" class="w-full px-3 py-2 border rounded-lg @error('required_ram_mb') border-red-500 @enderror" required>
                @error('required_ram_mb')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-semibold mb-2">CPU Cores *</label>
                <input type="number" name="required_cpu_cores" value="{{ old('required_cpu_cores', $containerTemplate->required_cpu_cores) }}" step="0.1" class="w-full px-3 py-2 border rounded-lg @error('required_cpu_cores') border-red-500 @enderror" required>
                @error('required_cpu_cores')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-semibold mb-2">Storage (GB) *</label>
                <input type="number" name="required_storage_gb" value="{{ old('required_storage_gb', $containerTemplate->required_storage_gb) }}" class="w-full px-3 py-2 border rounded-lg @error('required_storage_gb') border-red-500 @enderror" required>
                @error('required_storage_gb')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mb-6">
            <label class="block text-sm font-semibold mb-2">Environment Variables (JSON)</label>
            <textarea name="environment_variables" rows="4" class="w-full px-3 py-2 border rounded-lg font-mono text-sm @error('environment_variables') border-red-500 @enderror">{{ old('environment_variables', json_encode($containerTemplate->environment_variables, JSON_PRETTY_PRINT)) }}</textarea>
            <p class="text-gray-500 text-sm mt-1">Array of objects with: key, label, default, required (bool), secret (bool)</p>
            @error('environment_variables')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-6">
            <label class="block text-sm font-semibold mb-2">Volume Paths (JSON)</label>
            <textarea name="volume_paths" rows="3" class="w-full px-3 py-2 border rounded-lg font-mono text-sm @error('volume_paths') border-red-500 @enderror">{{ old('volume_paths', json_encode($containerTemplate->volume_paths, JSON_PRETTY_PRINT)) }}</textarea>
            <p class="text-gray-500 text-sm mt-1">Object mapping volume name to container mount path</p>
            @error('volume_paths')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-6">
            <label class="block text-sm font-semibold mb-2">Compose Services (JSON)</label>
            <textarea name="compose_services" rows="4" class="w-full px-3 py-2 border rounded-lg font-mono text-sm @error('compose_services') border-red-500 @enderror">{{ old('compose_services', json_encode($containerTemplate->compose_services, JSON_PRETTY_PRINT)) }}</textarea>
            <p class="text-gray-500 text-sm mt-1">Docker Compose service definitions (sidecar services)</p>
            @error('compose_services')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-6">
            <label class="block text-sm font-semibold mb-2">Setup Commands (JSON)</label>
            <textarea name="setup_commands" rows="3" class="w-full px-3 py-2 border rounded-lg font-mono text-sm @error('setup_commands') border-red-500 @enderror">{{ old('setup_commands', json_encode($containerTemplate->setup_commands, JSON_PRETTY_PRINT)) }}</textarea>
            <p class="text-gray-500 text-sm mt-1">Array of shell commands to run after deployment</p>
            @error('setup_commands')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-6">
            <label class="block text-sm font-semibold mb-2">Available Versions (JSON)</label>
            <textarea name="versions" rows="3" class="w-full px-3 py-2 border rounded-lg font-mono text-sm @error('versions') border-red-500 @enderror">{{ old('versions', json_encode($containerTemplate->versions, JSON_PRETTY_PRINT)) }}</textarea>
            <p class="text-gray-500 text-sm mt-1">Array of version strings available for selection (e.g., ["3.11","3.12"] or ["8.0","8.1","8.2"])</p>
            @error('versions')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-2 gap-6 mb-8">
            <div>
                <label class="block text-sm font-semibold mb-2">Sort Order *</label>
                <input type="number" name="order" value="{{ old('order', $containerTemplate->order) }}" class="w-full px-3 py-2 border rounded-lg @error('order') border-red-500 @enderror" required>
                @error('order')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-end">
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $containerTemplate->is_active) ? 'checked' : '' }} class="mr-2">
                    <span class="text-sm font-semibold">Active</span>
                </label>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Update Template
            </button>
            <a href="{{ route('admin.container-templates.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
