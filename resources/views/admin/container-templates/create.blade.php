@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8 max-w-4xl">
    <h1 class="text-3xl font-bold mb-8">Create Container Template</h1>

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

    <form method="POST" action="{{ route('admin.container-templates.store') }}" class="bg-white rounded-lg shadow p-6">
        @csrf

        <div class="grid grid-cols-2 gap-6 mb-6">
            <div>
                <label class="block text-sm font-semibold mb-2">Name *</label>
                <input type="text" name="name" value="{{ old('name') }}" class="w-full px-3 py-2 border rounded-lg @error('name') border-red-500 @enderror" required>
                @error('name')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-semibold mb-2">Slug *</label>
                <input type="text" name="slug" value="{{ old('slug') }}" class="w-full px-3 py-2 border rounded-lg @error('slug') border-red-500 @enderror" required>
                @error('slug')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mb-6">
            <label class="block text-sm font-semibold mb-2">Description</label>
            <textarea name="description" rows="3" class="w-full px-3 py-2 border rounded-lg @error('description') border-red-500 @enderror">{{ old('description') }}</textarea>
            @error('description')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-2 gap-6 mb-6">
            <div>
                <label class="block text-sm font-semibold mb-2">Category *</label>
                <select name="category" class="w-full px-3 py-2 border rounded-lg @error('category') border-red-500 @enderror" required>
                    <option value="">Select category</option>
                    <option value="web" {{ old('category') === 'web' ? 'selected' : '' }}>Web</option>
                    <option value="database" {{ old('category') === 'database' ? 'selected' : '' }}>Database</option>
                    <option value="utility" {{ old('category') === 'utility' ? 'selected' : '' }}>Utility</option>
                    <option value="cache" {{ old('category') === 'cache' ? 'selected' : '' }}>Cache</option>
                </select>
                @error('category')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-semibold mb-2">Docker Image *</label>
                <input type="text" name="docker_image" value="{{ old('docker_image') }}" class="w-full px-3 py-2 border rounded-lg @error('docker_image') border-red-500 @enderror" required placeholder="e.g., wordpress:latest">
                @error('docker_image')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="grid grid-cols-4 gap-4 mb-6">
            <div>
                <label class="block text-sm font-semibold mb-2">Port *</label>
                <input type="number" name="default_port" value="{{ old('default_port', 80) }}" class="w-full px-3 py-2 border rounded-lg @error('default_port') border-red-500 @enderror" required>
                @error('default_port')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-semibold mb-2">RAM (MB) *</label>
                <input type="number" name="required_ram_mb" value="{{ old('required_ram_mb', 512) }}" class="w-full px-3 py-2 border rounded-lg @error('required_ram_mb') border-red-500 @enderror" required>
                @error('required_ram_mb')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-semibold mb-2">CPU Cores *</label>
                <input type="number" name="required_cpu_cores" value="{{ old('required_cpu_cores', 0.5) }}" step="0.1" class="w-full px-3 py-2 border rounded-lg @error('required_cpu_cores') border-red-500 @enderror" required>
                @error('required_cpu_cores')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-semibold mb-2">Storage (GB) *</label>
                <input type="number" name="required_storage_gb" value="{{ old('required_storage_gb', 2) }}" class="w-full px-3 py-2 border rounded-lg @error('required_storage_gb') border-red-500 @enderror" required>
                @error('required_storage_gb')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mb-6">
            <label class="block text-sm font-semibold mb-2">Environment Variables (JSON)</label>
            <textarea name="environment_variables" rows="4" class="w-full px-3 py-2 border rounded-lg font-mono text-sm @error('environment_variables') border-red-500 @enderror" placeholder='[{"key":"VAR_NAME","label":"Variable Label","default":"value","required":true,"secret":false}]'>{{ old('environment_variables') }}</textarea>
            <p class="text-gray-500 text-sm mt-1">Array of objects with: key, label, default, required (bool), secret (bool)</p>
            @error('environment_variables')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-6">
            <label class="block text-sm font-semibold mb-2">Volume Paths (JSON)</label>
            <textarea name="volume_paths" rows="3" class="w-full px-3 py-2 border rounded-lg font-mono text-sm @error('volume_paths') border-red-500 @enderror" placeholder='{"volume_name":"/mount/path"}'>{{ old('volume_paths') }}</textarea>
            <p class="text-gray-500 text-sm mt-1">Object mapping volume name to container mount path</p>
            @error('volume_paths')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-6">
            <label class="block text-sm font-semibold mb-2">Compose Services (JSON)</label>
            <textarea name="compose_services" rows="4" class="w-full px-3 py-2 border rounded-lg font-mono text-sm @error('compose_services') border-red-500 @enderror" placeholder='{"mysql":{"image":"mysql:8.0",...}}'>{{ old('compose_services') }}</textarea>
            <p class="text-gray-500 text-sm mt-1">Docker Compose service definitions (sidecar services like MySQL, Redis)</p>
            @error('compose_services')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-6">
            <label class="block text-sm font-semibold mb-2">Setup Commands (JSON)</label>
            <textarea name="setup_commands" rows="3" class="w-full px-3 py-2 border rounded-lg font-mono text-sm @error('setup_commands') border-red-500 @enderror" placeholder='["command1","command2"]'>{{ old('setup_commands') }}</textarea>
            <p class="text-gray-500 text-sm mt-1">Array of shell commands to run after deployment</p>
            @error('setup_commands')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-2 gap-6 mb-8">
            <div>
                <label class="block text-sm font-semibold mb-2">Sort Order *</label>
                <input type="number" name="order" value="{{ old('order', 0) }}" class="w-full px-3 py-2 border rounded-lg @error('order') border-red-500 @enderror" required>
                @error('order')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-end">
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }} class="mr-2">
                    <span class="text-sm font-semibold">Active</span>
                </label>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Create Template
            </button>
            <a href="{{ route('admin.container-templates.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
