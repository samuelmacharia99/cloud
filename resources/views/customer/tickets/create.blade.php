@extends('layouts.app')

@section('title', 'Create Support Ticket')

@section('content')
<div class="py-12">
    <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex justify-between items-start">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Create Support Ticket</h1>
                    <p class="mt-2 text-gray-600 dark:text-gray-400">Submit a support request to our team</p>
                </div>
                <a href="{{ route('customer.tickets.index') }}" class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white">
                    ← Back to Tickets
                </a>
            </div>

            <!-- Form Card -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
                <form action="{{ route('customer.tickets.store') }}" method="POST" class="space-y-6">
                    @csrf

                    <!-- Title -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Title</label>
                        <input
                            type="text"
                            name="title"
                            required
                            value="{{ old('title') }}"
                            placeholder="Brief description of your issue..."
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500"
                        >
                        @error('title')
                        <p class="text-red-600 dark:text-red-400 text-sm mt-2">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
                        <textarea
                            name="description"
                            required
                            rows="8"
                            placeholder="Please provide detailed information about your issue..."
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500"
                        >{{ old('description') }}</textarea>
                        @error('description')
                        <p class="text-red-600 dark:text-red-400 text-sm mt-2">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Priority -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Priority</label>
                        <select name="priority" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500">
                            <option value="">Select priority...</option>
                            @foreach($priorityOptions as $priority)
                            <option value="{{ $priority }}" {{ old('priority') === $priority ? 'selected' : '' }}>{{ ucfirst($priority) }}</option>
                            @endforeach
                        </select>
                        @error('priority')
                        <p class="text-red-600 dark:text-red-400 text-sm mt-2">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Buttons -->
                    <div class="flex gap-3 pt-4">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition-colors">
                            Create Ticket
                        </button>
                        <a href="{{ route('customer.tickets.index') }}" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded-lg font-medium transition-colors">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
