@extends('layouts.admin')

@section('title', 'Create Container Template')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.container-templates.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Container Templates</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="font-medium text-slate-600 dark:text-slate-400">Create</p>
</div>
@endsection

@section('content')
@php
    $inputClass = 'w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400';
    $labelClass = 'block text-sm font-semibold text-slate-900 dark:text-white mb-2';
    $hintClass = 'text-slate-500 dark:text-slate-400 text-sm mt-1';
    $errorClass = 'text-red-600 dark:text-red-400 text-sm mt-1';
@endphp

<div class="space-y-6 max-w-4xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Create Container Template</h1>
        <p class="mt-1 text-slate-600 dark:text-slate-400">Define a stack image, resource floor, and optional sidecars.</p>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 px-4 py-3 text-red-800 dark:text-red-200">
            <p class="mb-2 font-semibold">Please fix the following errors:</p>
            <ul class="list-inside list-disc space-y-1 text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.container-templates.store') }}" class="ui-card p-6 space-y-6">
        @csrf

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}">Name *</label>
                <input type="text" name="name" value="{{ old('name') }}" class="{{ $inputClass }} @error('name') border-red-500 @enderror" required>
                @error('name')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">Slug *</label>
                <input type="text" name="slug" value="{{ old('slug') }}" class="{{ $inputClass }} @error('slug') border-red-500 @enderror" required>
                @error('slug')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
            </div>
        </div>

        <div>
            <label class="{{ $labelClass }}">Description</label>
            <textarea name="description" rows="3" class="{{ $inputClass }} @error('description') border-red-500 @enderror">{{ old('description') }}</textarea>
            @error('description')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}">Category *</label>
                <select name="category" class="{{ $inputClass }} @error('category') border-red-500 @enderror" required>
                    <option value="">Select category</option>
                    <option value="web" {{ old('category') === 'web' ? 'selected' : '' }}>Web</option>
                    <option value="database" {{ old('category') === 'database' ? 'selected' : '' }}>Database</option>
                    <option value="utility" {{ old('category') === 'utility' ? 'selected' : '' }}>Utility</option>
                    <option value="cache" {{ old('category') === 'cache' ? 'selected' : '' }}>Cache</option>
                </select>
                @error('category')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">Docker Image *</label>
                <input type="text" name="docker_image" value="{{ old('docker_image') }}" class="{{ $inputClass }} @error('docker_image') border-red-500 @enderror" required placeholder="e.g., wordpress:latest">
                @error('docker_image')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <label class="{{ $labelClass }}">Port *</label>
                <input type="number" name="default_port" value="{{ old('default_port', 80) }}" class="{{ $inputClass }} @error('default_port') border-red-500 @enderror" required>
                @error('default_port')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">RAM (MB) *</label>
                <input type="number" name="required_ram_mb" value="{{ old('required_ram_mb', 512) }}" class="{{ $inputClass }} @error('required_ram_mb') border-red-500 @enderror" required>
                @error('required_ram_mb')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">CPU Cores *</label>
                <input type="number" name="required_cpu_cores" value="{{ old('required_cpu_cores', 0.5) }}" step="0.1" class="{{ $inputClass }} @error('required_cpu_cores') border-red-500 @enderror" required>
                @error('required_cpu_cores')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">Storage (GB) *</label>
                <input type="number" name="required_storage_gb" value="{{ old('required_storage_gb', 2) }}" class="{{ $inputClass }} @error('required_storage_gb') border-red-500 @enderror" required>
                @error('required_storage_gb')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
            </div>
        </div>

        <div>
            <label class="{{ $labelClass }}">Environment Variables (JSON)</label>
            <textarea name="environment_variables" rows="4" class="{{ $inputClass }} font-mono @error('environment_variables') border-red-500 @enderror" placeholder='[{"key":"VAR_NAME","label":"Variable Label","default":"value","required":true,"secret":false}]'>{{ old('environment_variables') }}</textarea>
            <p class="{{ $hintClass }}">Array of objects with: key, label, default, required (bool), secret (bool)</p>
            @error('environment_variables')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="{{ $labelClass }}">Volume Paths (JSON)</label>
            <textarea name="volume_paths" rows="3" class="{{ $inputClass }} font-mono @error('volume_paths') border-red-500 @enderror" placeholder='{"volume_name":"/mount/path"}'>{{ old('volume_paths') }}</textarea>
            <p class="{{ $hintClass }}">Object mapping volume name to container mount path</p>
            @error('volume_paths')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="{{ $labelClass }}">Compose Services (JSON)</label>
            <textarea name="compose_services" rows="4" class="{{ $inputClass }} font-mono @error('compose_services') border-red-500 @enderror" placeholder='{"mysql":{"image":"mysql:8.0",...}}'>{{ old('compose_services') }}</textarea>
            <p class="{{ $hintClass }}">Docker Compose service definitions (sidecar services like MySQL, Redis)</p>
            @error('compose_services')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="{{ $labelClass }}">Setup Commands (JSON)</label>
            <textarea name="setup_commands" rows="3" class="{{ $inputClass }} font-mono @error('setup_commands') border-red-500 @enderror" placeholder='["command1","command2"]'>{{ old('setup_commands') }}</textarea>
            <p class="{{ $hintClass }}">Array of shell commands to run after deployment</p>
            @error('setup_commands')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="{{ $labelClass }}">Available Versions (JSON)</label>
            <textarea name="versions" rows="3" class="{{ $inputClass }} font-mono @error('versions') border-red-500 @enderror" placeholder='["3.11","3.12"]'>{{ old('versions') ? json_encode(old('versions')) : '' }}</textarea>
            <p class="{{ $hintClass }}">Array of version strings available for selection</p>
            @error('versions')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}">Sort Order *</label>
                <input type="number" name="order" value="{{ old('order', 0) }}" class="{{ $inputClass }} @error('order') border-red-500 @enderror" required>
                @error('order')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
            </div>
            <div class="flex items-end">
                <label class="flex cursor-pointer items-center text-slate-900 dark:text-white">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }} class="mr-2 rounded border-slate-300 dark:border-slate-600 dark:bg-slate-800">
                    <span class="text-sm font-semibold">Active</span>
                </label>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}">Health Check Timeout (seconds) *</label>
                <input type="number" name="health_check_timeout_seconds" value="{{ old('health_check_timeout_seconds', 120) }}" min="30" max="900" class="{{ $inputClass }} @error('health_check_timeout_seconds') border-red-500 @enderror" required>
                @error('health_check_timeout_seconds')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
            </div>
            <div class="flex items-end">
                <label class="flex cursor-pointer items-center text-slate-900 dark:text-white">
                    <input type="checkbox" name="strict_health_check" value="1" {{ old('strict_health_check', true) ? 'checked' : '' }} class="mr-2 rounded border-slate-300 dark:border-slate-600 dark:bg-slate-800">
                    <span class="text-sm font-semibold">Strict health check</span>
                </label>
            </div>
        </div>

        <div class="flex flex-wrap gap-3 border-t border-slate-200 dark:border-slate-700 pt-6">
            <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition">
                Create Template
            </button>
            <a href="{{ route('admin.container-templates.index') }}" class="px-6 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-lg font-medium hover:bg-slate-200 dark:hover:bg-slate-700 transition">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
