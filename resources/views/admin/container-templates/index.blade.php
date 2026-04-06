@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold">Container Templates</h1>
        <a href="{{ route('admin.container-templates.create') }}" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Create Template
        </a>
    </div>

    @if ($message = Session::get('success'))
        <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
            {{ $message }}
        </div>
    @endif

    @if ($message = Session::get('error'))
        <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
            {{ $message }}
        </div>
    @endif

    <div class="grid gap-6">
        @forelse ($templates as $template)
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-2">
                            <h2 class="text-xl font-semibold">{{ $template->name }}</h2>
                            <span class="px-2 py-1 text-sm rounded {{ $template->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                {{ $template->is_active ? 'Active' : 'Inactive' }}
                            </span>
                            <span class="px-2 py-1 text-sm bg-blue-100 text-blue-800 rounded capitalize">
                                {{ $template->category }}
                            </span>
                        </div>
                        <p class="text-gray-600 mb-4">{{ $template->description }}</p>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                            <div>
                                <p class="text-sm text-gray-500">Docker Image</p>
                                <p class="font-mono text-sm">{{ $template->docker_image }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Port</p>
                                <p class="font-semibold">{{ $template->default_port }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">CPU Cores</p>
                                <p class="font-semibold">{{ $template->required_cpu_cores }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">RAM / Storage</p>
                                <p class="font-semibold">{{ $template->required_ram_mb }}MB / {{ $template->required_storage_gb }}GB</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 text-sm text-gray-600">
                            @if ($template->environment_variables)
                                <div>
                                    <p class="font-semibold">{{ count($template->environment_variables) }} Environment Variables</p>
                                </div>
                            @endif
                            @if ($template->volume_paths)
                                <div>
                                    <p class="font-semibold">{{ count($template->volume_paths) }} Volumes</p>
                                </div>
                            @endif
                        </div>

                        <p class="text-sm text-gray-500 mt-2">
                            Using: <strong>{{ $template->products()->count() }}</strong> products
                        </p>
                    </div>

                    <div class="flex gap-2 ml-4">
                        <a href="{{ route('admin.container-templates.edit', $template) }}" class="px-3 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200">
                            Edit
                        </a>
                        <form method="POST" action="{{ route('admin.container-templates.destroy', $template) }}" style="display:inline;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="px-3 py-2 bg-red-100 text-red-700 rounded hover:bg-red-200" onclick="return confirm('Are you sure?')">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-lg shadow p-6 text-center">
                <p class="text-gray-500">No container templates found</p>
                <a href="{{ route('admin.container-templates.create') }}" class="text-blue-600 hover:text-blue-700">Create your first template</a>
            </div>
        @endforelse
    </div>
</div>
@endsection
