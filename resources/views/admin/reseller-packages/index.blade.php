@extends('layouts.admin')

@section('title', 'Reseller Packages')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Reseller Packages</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Configure pricing tiers for reseller program.</p>
        </div>
        <a href="{{ route('admin.reseller-packages.create') }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Package
        </a>
    </div>

    <!-- Billing Cycle Tabs -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
        <div class="flex border-b border-slate-200 dark:border-slate-800">
            <a href="{{ route('admin.reseller-packages.index', ['cycle' => 'monthly']) }}" class="flex-1 px-6 py-4 text-center font-medium {{ $cycle === 'monthly' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' }} transition-colors">
                Monthly Packages ({{ $monthly }})
            </a>
            <a href="{{ route('admin.reseller-packages.index', ['cycle' => 'annually']) }}" class="flex-1 px-6 py-4 text-center font-medium {{ $cycle === 'annually' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' }} transition-colors">
                Annual Packages ({{ $annually }})
            </a>
        </div>

        <!-- Packages Grid -->
        <div class="p-8">
            @if ($packages->count() > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach ($packages as $package)
                        <div class="bg-gradient-to-br {{ $package->active ? 'from-slate-50 to-slate-50 dark:from-slate-800 dark:to-slate-800' : 'from-slate-100 to-slate-100 dark:from-slate-700 dark:to-slate-700' }} rounded-xl border {{ $package->active ? 'border-slate-200 dark:border-slate-700' : 'border-slate-300 dark:border-slate-600' }} p-6 {{ !$package->active ? 'opacity-60' : '' }}">
                            <!-- Header -->
                            <div class="flex items-start justify-between mb-4">
                                <div>
                                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">{{ $package->name }}</h3>
                                    @if ($package->description)
                                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">{{ $package->description }}</p>
                                    @endif
                                </div>
                                @if (!$package->active)
                                    <span class="px-2 py-1 bg-yellow-100 dark:bg-yellow-900 text-yellow-700 dark:text-yellow-300 text-xs rounded font-medium">Inactive</span>
                                @endif
                            </div>

                            <!-- Price -->
                            <div class="mb-6 pb-6 border-b border-slate-200 dark:border-slate-700">
                                <p class="text-sm text-slate-600 dark:text-slate-400">Price</p>
                                <p class="text-3xl font-bold text-slate-900 dark:text-white mt-1">
                                    Ksh {{ number_format($package->price, 0) }}
                                    <span class="text-lg text-slate-600 dark:text-slate-400 font-normal">/{{ $cycle === 'monthly' ? 'mo' : 'yr' }}</span>
                                </p>
                            </div>

                            <!-- Features -->
                            <div class="space-y-3 mb-6">
                                <div class="flex items-center gap-3">
                                    <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                    </svg>
                                    <div>
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $package->storage_space }} GB Storage</p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400">Cloud space allocation</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                    </svg>
                                    <div>
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $package->max_users }} Users</p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400">Maximum user accounts</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex gap-2">
                                <a href="{{ route('admin.reseller-packages.edit', $package) }}" class="flex-1 px-3 py-2 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 hover:bg-blue-200 dark:hover:bg-blue-800 rounded-lg text-sm font-medium transition-colors text-center">
                                    Edit
                                </a>
                                <form action="{{ route('admin.reseller-packages.destroy', $package) }}" method="POST" class="flex-1" onsubmit="return confirm('Are you sure?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="w-full px-3 py-2 bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300 hover:bg-red-200 dark:hover:bg-red-800 rounded-lg text-sm font-medium transition-colors">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Pagination -->
                @if ($packages->hasPages())
                    <div class="mt-6">
                        {{ $packages->links() }}
                    </div>
                @endif
            @else
                <div class="text-center py-12">
                    <svg class="mx-auto w-12 h-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m0 0l8-4m0 0l8 4m0 0v10l-8 4m0-10L4 7m0 10v10l8 4m8-4v-10l-8-4"/>
                    </svg>
                    <p class="text-slate-600 dark:text-slate-400 font-medium mt-2">No {{ $cycle }} packages yet</p>
                    <a href="{{ route('admin.reseller-packages.create') }}" class="text-blue-600 dark:text-blue-400 hover:underline text-sm mt-2 inline-block">
                        Create your first package
                    </a>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
