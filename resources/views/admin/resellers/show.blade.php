@extends('layouts.admin')

@section('title', 'Reseller: ' . $user->name)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.resellers.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Resellers</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">{{ $user->name }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header Card -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center text-white font-bold text-2xl">
                    {{ substr($user->name, 0, 1) }}
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $user->name }}</h1>
                    <p class="text-slate-600 dark:text-slate-400 mt-1">{{ $user->email }}</p>
                    @if ($user->company_name)
                        <p class="text-slate-600 dark:text-slate-400 mt-1 font-medium">{{ $user->company_name }}</p>
                    @endif
                </div>
            </div>

            <!-- Status & Actions -->
            <div class="text-right space-y-2">
                <div>
                    <span class="inline-block px-3 py-1 bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-300 rounded-full text-sm font-medium">
                        Active Reseller
                    </span>
                </div>
                <form action="{{ route('admin.resellers.demote', $user) }}" method="POST" class="inline">
                    @csrf
                    <x-confirmation-dialog
                        title="Demote Reseller?"
                        message="This user will no longer have reseller privileges."
                        confirmText="Demote"
                        danger
                        :action="route('admin.resellers.demote', $user)"
                    >
                        <button type="button" class="px-4 py-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg transition text-sm font-medium">
                            Remove Reseller Status
                        </button>
                    </x-confirmation-dialog>
                </form>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 uppercase">Services Managed</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $services->count() }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 uppercase">Customers Served</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $customerIds->count() }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 uppercase">Package</p>
            <p class="text-lg font-bold text-slate-900 dark:text-white mt-2">{{ $user->resellerPackage?->name ?? '—' }}</p>
            @if($user->resellerPackage)
                <p class="text-xs text-slate-500 mt-1">Ksh {{ number_format($user->resellerPackage->price, 0) }}/{{ $user->resellerPackage->billing_cycle === 'monthly' ? 'mo' : 'yr' }}</p>
            @endif
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 uppercase">Member Since</p>
            <p class="text-lg font-bold text-slate-900 dark:text-white mt-2">{{ $user->created_at->format('M d, Y') }}</p>
        </div>
    </div>

    <!-- Tabbed Content -->
    <div x-data="{ activeTab: 'overview' }" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
        <!-- Tab Navigation -->
        <div class="border-b border-slate-200 dark:border-slate-800">
            <div class="flex gap-1 px-6">
                <button @click="activeTab = 'overview'" :class="activeTab === 'overview' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors">
                    Overview
                </button>
                <button @click="activeTab = 'services'" :class="activeTab === 'services' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors">
                    Services ({{ $services->count() }})
                </button>
                <button @click="activeTab = 'customers'" :class="activeTab === 'customers' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors">
                    Customers ({{ $customerIds->count() }})
                </button>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="p-6">
            <!-- Overview Tab -->
            <div x-show="activeTab === 'overview'" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Contact Information -->
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Contact Information</h3>
                        <div class="space-y-3 text-sm">
                            <div>
                                <p class="text-slate-600 dark:text-slate-400 mb-1">Email</p>
                                <p class="text-slate-900 dark:text-white font-medium">{{ $user->email }}</p>
                            </div>
                            @if ($user->phone)
                                <div>
                                    <p class="text-slate-600 dark:text-slate-400 mb-1">Phone</p>
                                    <p class="text-slate-900 dark:text-white font-medium">{{ $user->phone }}</p>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Reseller Information -->
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Reseller Information</h3>
                        <div class="space-y-3 text-sm">
                            <div>
                                <p class="text-slate-600 dark:text-slate-400 mb-1">Status</p>
                                <p class="text-slate-900 dark:text-white font-medium">Active</p>
                            </div>
                            <div>
                                <p class="text-slate-600 dark:text-slate-400 mb-1">Current Package</p>
                                @if ($user->resellerPackage)
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-slate-900 dark:text-white">{{ $user->resellerPackage->name }}</span>
                                        <span class="px-2 py-0.5 bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300 rounded text-xs">
                                            {{ ucfirst($user->resellerPackage->billing_cycle) }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                        Subscribed {{ $user->package_subscribed_at?->diffForHumans() }}
                                        &bull;
                                        {{ $user->getManagedActiveServicesCount() }} / {{ $user->resellerPackage->storage_space }} service slots
                                        &bull;
                                        {{ $customerIds->count() }} / {{ $user->resellerPackage->max_users }} customers
                                    </p>
                                @else
                                    <span class="text-amber-600 dark:text-amber-400 font-medium text-sm">No package assigned</span>
                                @endif
                            </div>
                            @if ($user->company)
                                <div>
                                    <p class="text-slate-600 dark:text-slate-400 mb-1">Company</p>
                                    <p class="text-slate-900 dark:text-white font-medium">{{ $user->company }}</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Placeholder Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-6 border-t border-slate-200 dark:border-slate-800">
                    <div class="p-6 bg-gradient-to-br from-amber-50 to-amber-50/50 dark:from-amber-950/20 dark:to-amber-950/10 border border-amber-200 dark:border-amber-900/30 rounded-lg">
                        <h4 class="font-semibold text-amber-900 dark:text-amber-200 mb-2">Pricing Tiers</h4>
                        <p class="text-sm text-amber-800 dark:text-amber-300">Configure custom pricing for this reseller (coming soon)</p>
                    </div>
                    <div class="p-6 bg-gradient-to-br from-purple-50 to-purple-50/50 dark:from-purple-950/20 dark:to-purple-950/10 border border-purple-200 dark:border-purple-900/30 rounded-lg">
                        <h4 class="font-semibold text-purple-900 dark:text-purple-200 mb-2">Commission & Wallet</h4>
                        <p class="text-sm text-purple-800 dark:text-purple-300">View earned commissions and wallet balance (coming soon)</p>
                    </div>
                </div>
            </div>

            <!-- Services Tab -->
            <div x-show="activeTab === 'services'">
                @if ($services->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-800">
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Service</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Customer</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Product</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Status</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Next Renewal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                @foreach ($services as $service)
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                        <td class="py-3 px-4">
                                            <a href="{{ route('admin.services.show', $service) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                                {{ $service->name }}
                                            </a>
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            {{ $service->user->name }}
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            {{ $service->product->name }}
                                        </td>
                                        <td class="py-3 px-4">
                                            <x-status-badge :status="$service->status" type="service" />
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            @if ($service->next_due_date)
                                                {{ $service->next_due_date->format('M d, Y') }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center py-12">
                        <p class="text-slate-600 dark:text-slate-400">No services managed yet</p>
                    </div>
                @endif
            </div>

            <!-- Customers Tab -->
            <div x-show="activeTab === 'customers'">
                @if ($customerIds->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-800">
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Customer</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Email</th>
                                    <th class="text-center py-3 px-4 font-semibold text-slate-900 dark:text-white">Services</th>
                                    <th class="text-right py-3 px-4 font-semibold text-slate-900 dark:text-white">Total Spend</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                @foreach ($customers as $customer)
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                        <td class="py-3 px-4">
                                            <a href="{{ route('admin.customers.show', $customer) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                                {{ $customer->name }}
                                            </a>
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            {{ $customer->email }}
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <span class="inline-block px-3 py-1 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300 rounded-full text-xs font-medium">
                                                {{ $customer->services_count ?? 0 }}
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-right font-medium text-slate-900 dark:text-white">
                                            <x-currency-formatter :amount="$customer->total_spent ?? 0" currency="KES" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center py-12">
                        <p class="text-slate-600 dark:text-slate-400">No customers served yet</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
