@extends('layouts.admin')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold">Database Templates</h1>
        <a href="{{ route('admin.database-templates.create') }}" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Create Database Template
        </a>
    </div>

    <div class="grid gap-4">
        @forelse ($templates as $template)
            <div class="bg-white dark:bg-slate-900 rounded-lg shadow p-5 border border-slate-200 dark:border-slate-700">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-2">
                            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $template->name }}</h2>
                            <span class="px-2 py-0.5 text-xs rounded {{ $template->is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-700' }}">
                                {{ $template->is_active ? 'Active' : 'Inactive' }}
                            </span>
                            <span class="px-2 py-0.5 text-xs rounded bg-blue-100 text-blue-700">
                                {{ strtoupper($template->type) }}
                            </span>
                            <span class="px-2 py-0.5 text-xs rounded bg-purple-100 text-purple-700">
                                {{ $template->hosting_type }}
                            </span>
                        </div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">{{ $template->description }}</p>
                        <p class="text-xs text-slate-500">Image: <span class="font-mono">{{ $template->docker_image }}</span></p>
                        <p class="text-xs text-slate-500">Port: {{ $template->default_port }} | RAM: {{ $template->required_ram_mb }}MB | Order: {{ $template->order }}</p>
                        @if(is_array($template->versions) && count($template->versions))
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach($template->versions as $version)
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-slate-100 text-slate-700">{{ $version }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('admin.database-templates.edit', $template) }}" class="px-3 py-1.5 bg-slate-100 text-slate-700 rounded hover:bg-slate-200">
                            Edit
                        </a>
                        <form method="POST" action="{{ route('admin.database-templates.destroy', $template) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="px-3 py-1.5 bg-red-100 text-red-700 rounded hover:bg-red-200">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-slate-900 rounded-lg shadow p-8 text-center border border-slate-200 dark:border-slate-700">
                <p class="text-slate-500">No database templates found.</p>
            </div>
        @endforelse
    </div>
</div>
@endsection
