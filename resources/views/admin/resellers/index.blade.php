@extends('layouts.admin')

@section('title', 'Resellers')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Resellers</p>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Resellers</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage reseller accounts and commissions.</p>
        </div>
        <a href="{{ route('admin.resellers.create') }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
            Add Reseller
        </a>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="ui-card p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Resellers</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $resellers->total() }}</p>
        </div>
        <div class="ui-card p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Services Managed</p>
            <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-2">{{ $totalServices }}</p>
        </div>
        <div class="ui-card p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Unique Customers</p>
            <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2">{{ $totalCustomers }}</p>
        </div>
    </div>

    <!-- Resellers Table -->
    <div class="ui-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Reseller</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Company</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Package</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold text-slate-900 dark:text-white">Domains</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold text-slate-900 dark:text-white">Customers</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Created</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($resellers as $reseller)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <!-- Reseller Info -->
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center text-white font-bold text-sm">
                                        {{ substr($reseller->name, 0, 1) }}
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $reseller->name }}</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $reseller->email }}</p>
                                    </div>
                                </div>
                            </td>

                            <!-- Company Name -->
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                {{ $reseller->company_name ?? '—' }}
                            </td>

                            <!-- Package -->
                            <td class="px-6 py-4 text-sm text-slate-900 dark:text-white">
                                {{ $reseller->resellerPackage?->name ?? '—' }}
                            </td>

                            <!-- Domains Count -->
                            <td class="px-6 py-4 text-center">
                                <span class="inline-block px-3 py-1 bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300 rounded-full text-sm font-medium">
                                    {{ $reseller->managed_domains_count ?? 0 }}
                                </span>
                            </td>

                            <!-- Customers Count -->
                            <td class="px-6 py-4 text-center">
                                <span class="inline-block px-3 py-1 bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-300 rounded-full text-sm font-medium">
                                    {{ $reseller->customers_count ?? 0 }}
                                </span>
                            </td>

                            <!-- Status -->
                            <td class="px-6 py-4 text-center">
                                @if ($reseller->isResellerSuspended())
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300" @if($reseller->reseller_suspension_reason) title="{{ $reseller->reseller_suspension_reason }}" @endif>
                                        Suspended
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                                        Active
                                    </span>
                                @endif
                            </td>

                            <!-- Created Date -->
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                {{ $reseller->created_at->format('M d, Y') }}
                            </td>

                            <!-- Actions -->
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="{{ route('admin.resellers.show', $reseller) }}" class="text-blue-600 dark:text-blue-400 hover:underline text-sm font-medium" title="View reseller">
                                        View
                                    </a>
                                    <a href="{{ route('admin.resellers.edit', $reseller) }}" class="text-blue-600 dark:text-blue-400 hover:underline text-sm font-medium" title="Edit reseller">
                                        Edit
                                    </a>
                                    <form method="POST" action="{{ route('admin.resellers.impersonate', $reseller) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition" title="View as this reseller">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center">
                                <div class="space-y-2">
                                    <svg class="mx-auto w-12 h-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 10H9"/>
                                    </svg>
                                    <p class="text-slate-600 dark:text-slate-400 font-medium">No resellers found</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if ($resellers->hasPages())
            <div class="border-t border-slate-200 dark:border-slate-800 px-6 py-4">
                {{ $resellers->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
