@extends('layouts.reseller')

@section('title', 'New Support Ticket')

@section('content')
<div class="max-w-2xl space-y-6">
    <div>
        <a href="{{ route('reseller.tickets.index') }}" class="text-sm text-purple-600 hover:text-purple-700">← Back to tickets</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">New Support Ticket</h1>
    </div>

    <form action="{{ route('reseller.tickets.store') }}" method="POST" enctype="multipart/form-data" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-2">Title</label>
            <input type="text" name="title" value="{{ old('title') }}" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg">
        </div>
        <div>
            <label class="block text-sm font-medium mb-2">Priority</label>
            <select name="priority" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg">
                @foreach ($priorityOptions as $priority)
                    <option value="{{ $priority }}" @selected(old('priority', 'medium') === $priority)>{{ ucfirst($priority) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium mb-2">Description</label>
            <textarea name="description" rows="6" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg">{{ old('description') }}</textarea>
        </div>
        <x-ticket-attachment-input />
        <button type="submit" class="px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg">Submit Ticket</button>
    </form>
</div>
@endsection
