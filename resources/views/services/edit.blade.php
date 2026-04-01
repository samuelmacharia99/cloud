@extends('layouts.app')

@section('title', 'Edit Service')

@section('content')
<div class="max-w-2xl space-y-8">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Edit Service</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Update service details and status.</p>
    </div>

    <form action="{{ route('services.update', $service) }}" method="POST" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 space-y-6">
        @csrf
        @method('PUT')

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Service Name</label>
            <input type="text" name="name" value="{{ $service->name }}" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            @error('name') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Status</label>
            <select name="status" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="active" {{ $service->status === 'active' ? 'selected' : '' }}>Active</option>
                <option value="suspended" {{ $service->status === 'suspended' ? 'selected' : '' }}>Suspended</option>
                <option value="terminated" {{ $service->status === 'terminated' ? 'selected' : '' }}>Terminated</option>
                <option value="cancelled" {{ $service->status === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
            </select>
            @error('status') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing Cycle</label>
            <select name="billing_cycle" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="monthly" {{ $service->billing_cycle === 'monthly' ? 'selected' : '' }}>Monthly</option>
                <option value="quarterly" {{ $service->billing_cycle === 'quarterly' ? 'selected' : '' }}>Quarterly</option>
                <option value="semi-annual" {{ $service->billing_cycle === 'semi-annual' ? 'selected' : '' }}>Semi-Annual</option>
                <option value="annual" {{ $service->billing_cycle === 'annual' ? 'selected' : '' }}>Annual</option>
            </select>
            @error('billing_cycle') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Next Due Date</label>
            <input type="date" name="next_due_date" value="{{ $service->next_due_date->format('Y-m-d') }}" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            @error('next_due_date') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-4">
            <button type="submit" class="px-6 py-2.5 rounded-lg bg-blue-600 dark:bg-blue-500 text-white font-medium hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
                Update Service
            </button>
            <a href="{{ route('services.show', $service) }}" class="px-6 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
