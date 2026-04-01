@extends('layouts.app')

@section('title', 'Services')

@section('content')
<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Services</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your active services and subscriptions.</p>
        </div>
        @auth
            @if (auth()->user()->is_admin)
                <a href="{{ route('services.create') }}" class="px-6 py-2.5 rounded-lg bg-blue-600 dark:bg-blue-500 text-white text-sm font-medium hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
                    + New Service
                </a>
            @endif
        @endauth
    </div>

    <!-- Services Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Service</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Product</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Status</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase">Next Due</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($services as $service)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="px-6 py-4">
                                <p class="font-semibold text-slate-900 dark:text-white">{{ $service->name }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600 dark:text-slate-400">{{ $service->product->name }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium {{ $service->status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-200' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' }}">
                                    {{ ucfirst($service->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600 dark:text-slate-400">{{ $service->next_due_date->format('M d, Y') }}</p>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('services.show', $service) }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <p class="text-slate-500 dark:text-slate-400">No services found</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($services->hasPages())
        <div class="flex items-center justify-center">
            {{ $services->links() }}
        </div>
    @endif
</div>
@endsection
