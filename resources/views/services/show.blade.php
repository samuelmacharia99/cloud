@extends('layouts.app')

@section('title', $service->name)

@section('content')
<div class="space-y-8">
    <div>
        <a href="{{ route('services.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">← Back to services</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Service Details -->
        <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 p-8 space-y-6">
            <div>
                <h1 class="text-3xl font-bold text-slate-900">{{ $service->name }}</h1>
                <p class="text-slate-600 mt-2">{{ $service->product->name }}</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm font-medium text-slate-600 uppercase">Status</p>
                    <p class="text-lg font-semibold text-slate-900 mt-1">{{ ucfirst($service->status) }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-600 uppercase">Billing Cycle</p>
                    <p class="text-lg font-semibold text-slate-900 mt-1">{{ ucfirst($service->billing_cycle) }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-600 uppercase">Next Due Date</p>
                    <p class="text-lg font-semibold text-slate-900 mt-1">{{ $service->next_due_date->format('M d, Y') }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-600 uppercase">Created</p>
                    <p class="text-lg font-semibold text-slate-900 mt-1">{{ $service->created_at->format('M d, Y') }}</p>
                </div>
            </div>

            @if ($service->notes)
                <div class="pt-4 border-t border-slate-200">
                    <p class="text-sm font-medium text-slate-600 uppercase mb-2">Notes</p>
                    <p class="text-slate-700">{{ $service->notes }}</p>
                </div>
            @endif
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Product Details -->
            <div class="bg-white rounded-2xl border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-900 mb-4">Product</h3>
                <div class="space-y-3">
                    <div>
                        <p class="text-xs text-slate-600 uppercase">Price</p>
                        <p class="text-xl font-bold text-slate-900">${{ number_format($service->product->price, 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-600 uppercase">Billing</p>
                        <p class="text-slate-900">{{ ucfirst($service->product->billing_cycle) }}</p>
                    </div>
                    @if ($service->product->setup_fee > 0)
                        <div>
                            <p class="text-xs text-slate-600 uppercase">Setup Fee</p>
                            <p class="text-slate-900">${{ number_format($service->product->setup_fee, 2) }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Customer -->
            <div class="bg-white rounded-2xl border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-900 mb-4">Customer</h3>
                <p class="font-medium text-slate-900">{{ $service->user->name }}</p>
                <p class="text-sm text-slate-600">{{ $service->user->email }}</p>
            </div>

            <!-- Actions -->
            @auth
                @if (auth()->user()->is_admin)
                    <div class="flex gap-2">
                        <a href="{{ route('services.edit', $service) }}" class="flex-1 px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50 transition-colors">
                            Edit
                        </a>
                        <form action="{{ route('services.destroy', $service) }}" method="POST" onsubmit="return confirm('Are you sure?');" class="flex-1">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="w-full px-4 py-2 rounded-lg bg-red-100 text-red-700 text-sm font-medium hover:bg-red-200 transition-colors">
                                Delete
                            </button>
                        </form>
                    </div>
                @endif
            @endauth
        </div>
    </div>
</div>
@endsection
