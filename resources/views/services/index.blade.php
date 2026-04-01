@extends('layouts.app')

@section('title', 'Services')

@section('content')
<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Services</h1>
            <p class="text-slate-600 mt-1">Manage your active services and subscriptions.</p>
        </div>
        @auth
            @if (auth()->user()->is_admin)
                <a href="{{ route('services.create') }}" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition-colors">
                    + New Service
                </a>
            @endif
        @endauth
    </div>

    <!-- Services Table -->
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Service</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Product</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Status</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Next Due</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($services as $service)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4">
                                <p class="font-semibold text-slate-900">{{ $service->name }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600">{{ $service->product->name }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium {{ $service->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">
                                    {{ ucfirst($service->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600">{{ $service->next_due_date->format('M d, Y') }}</p>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('services.show', $service) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <p class="text-slate-500">No services found</p>
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
