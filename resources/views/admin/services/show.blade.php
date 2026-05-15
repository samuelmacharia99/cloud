@extends('layouts.admin')

@section('title', 'Service #' . $service->id)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.services.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Services</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">#{{ $service->id }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6" x-data="{ editDetailsModal: false }">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Service #{{ $service->id }}</h1>
                <p class="text-slate-600 dark:text-slate-400 mt-2">{{ $service->product->name }} • {{ ucfirst(str_replace('_', ' ', $service->product->type)) }}</p>

                <!-- Status badge -->
                <div class="mt-4">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if(\$service->status->value === 'active')
                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                        @elseif(\$service->status->value === 'pending')
                            bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                        @elseif(\$service->status->value === 'provisioning')
                            bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                        @elseif(\$service->status->value === 'suspended')
                            bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300
                        @elseif(\$service->status->value === 'terminated')
                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                        @elseif(\$service->status->value === 'failed')
                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                        @endif
                    ">
                        {{ ucfirst(\$service->status->value) }}
                    </span>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2 flex-wrap">
                @if (in_array(\$service->status->value, ['pending', 'provisioning']))
                    <form method="POST" action="{{ route('admin.services.provision', $service) }}" class="inline">
                        @csrf
                        <button type="submit" class="px-4 py-2 {{ \$service->status->value === 'provisioning' ? 'bg-orange-600 hover:bg-orange-700' : 'bg-blue-600 hover:bg-blue-700' }} text-white font-medium rounded-lg transition text-sm">
                            {{ \$service->status->value === 'provisioning' ? 'Retry Provisioning' : 'Provision' }}
                        </button>
                    </form>
                @endif

                @if (\$service->status->value === 'active')
                    <form method="POST" action="{{ route('admin.services.suspend', $service) }}" class="inline">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition text-sm">
                            Suspend
                        </button>
                    </form>
                @endif

                @if (\$service->status->value === 'suspended')
                    <form method="POST" action="{{ route('admin.services.unsuspend', $service) }}" class="inline">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition text-sm">
                            Unsuspend
                        </button>
                    </form>
                @endif

                @if (in_array(\$service->status->value, ['active', 'suspended', 'pending']))
                    <form method="POST" action="{{ route('admin.services.terminate', $service) }}" class="inline" onsubmit="return confirm('Are you sure you want to terminate this service?');">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition text-sm">
                            Terminate
                        </button>
                    </form>
                @endif

                <form method="POST" action="{{ route('admin.services.refresh-status', $service) }}" class="inline">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg transition text-sm">
                        Refresh Status
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Service Details -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Service Details</h2>
                    <button @click="editDetailsModal = true" class="px-3 py-1.5 text-xs bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 font-medium rounded-lg transition inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        <span>Edit Details</span>
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Status</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ ucfirst(\$service->status->value) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Billing Cycle</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ ucfirst($service->billing_cycle) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Next Due Date</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $service->next_due_date?->format('M d, Y') ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $service->created_at->format('M d, Y') }}</p>
                    </div>
                    @if ($service->suspend_date)
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Suspended</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $service->suspend_date->format('M d, Y') }}</p>
                        </div>
                    @endif
                    @if ($service->terminate_date)
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Terminated</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $service->terminate_date->format('M d, Y') }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Configuration -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Configuration</h2>
                    @if (\$service->status->value === 'provisioning')
                        <form method="POST" action="{{ route('admin.services.provision', $service) }}" class="inline">
                            @csrf
                            <button type="submit" class="px-3 py-1 text-xs bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-400 hover:bg-orange-200 dark:hover:bg-orange-900 font-medium rounded transition">
                                Retry Provisioning
                            </button>
                        </form>
                    @endif
                </div>
                <div class="space-y-3">
                    <!-- Provisioning Driver -->
                    @if ($service->provisioning_driver_key)
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Provisioning Driver</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $service->provisioning_driver_key }}</p>
                        </div>
                    @endif

                    <!-- Node (if assigned) -->
                    @if ($service->node_id)
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Node</p>
                            <div class="flex items-center justify-between mt-1">
                                <p class="text-sm text-slate-900 dark:text-white font-mono">{{ $service->node->name ?? 'Unknown' }}</p>
                                @if($service->node)
                                    <a href="{{ route('admin.nodes.show', $service->node) }}" class="text-xs text-blue-600 dark:text-blue-400 hover:underline">View Node</a>
                                @endif
                            </div>
                        </div>
                    @endif

                    <!-- External Reference -->
                    @if ($service->external_reference)
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">External Reference</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $service->external_reference }}</p>
                        </div>
                    @endif

                    <!-- Credentials (from credentials JSON field) -->
                    @if ($service->credentials && is_string($service->credentials))
                        @php
                            $creds = json_decode($service->credentials, true);
                        @endphp
                        @if ($creds)
                            @if (isset($creds['admin_username']) || isset($creds['username']))
                                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Username</p>
                                    <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $creds['admin_username'] ?? $creds['username'] ?? '-' }}</p>
                                </div>
                            @endif
                            @if (isset($creds['admin_email']) || isset($creds['access_url']))
                                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Admin Email</p>
                                    <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $creds['admin_email'] ?? $service->user->email ?? '-' }}</p>
                                </div>
                            @endif
                            @if (isset($creds['access_url']))
                                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Access URL</p>
                                    <p class="text-sm text-slate-900 dark:text-white font-mono mt-1 break-all">
                                        @if (filter_var($creds['access_url'], FILTER_VALIDATE_URL))
                                            <a href="{{ $creds['access_url'] }}" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline">{{ $creds['access_url'] }}</a>
                                        @else
                                            {{ $creds['access_url'] }}
                                        @endif
                                    </p>
                                </div>
                            @endif
                            @if (isset($creds['port']))
                                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Port</p>
                                    <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $creds['port'] }}</p>
                                </div>
                            @endif
                        @endif
                    @endif
                </div>
            </div>

            <!-- Service Metadata -->
            @if ($service->service_meta)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Service Metadata</h2>
                    <pre class="bg-slate-50 dark:bg-slate-800 p-4 rounded-lg text-xs text-slate-900 dark:text-slate-100 overflow-x-auto">{{ json_encode($service->service_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            @endif

            <!-- Server Credentials (VPS / Dedicated Server) -->
            @if ($service->product && \App\Models\Product::isServerType($service->product->type) && $service->credentials)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Server Credentials</h2>
                        <form method="POST" action="{{ route('admin.services.resend-credentials', $service) }}" class="inline">
                            @csrf
                            <button type="submit" class="px-3 py-1 text-xs bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 font-medium rounded transition">
                                Resend Credentials
                            </button>
                        </form>
                    </div>
                    <div class="space-y-3">
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Username</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ json_decode($service->credentials)->username }}</p>
                        </div>
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Password</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ json_decode($service->credentials)->password }}</p>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Container Panel (if applicable) -->
            @include('admin.services.partials.container-panel')
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Customer Info -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Customer</h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold">
                            {{ strtoupper(substr($service->user->name, 0, 1)) }}
                        </div>
                        <div>
                            <p class="font-medium text-slate-900 dark:text-white">{{ $service->user->name }}</p>
                            <p class="text-xs text-slate-600 dark:text-slate-400">{{ $service->user->email }}</p>
                        </div>
                    </div>
                    <a href="{{ route('admin.customers.show', $service->user) }}" class="block mt-4 px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                        View Customer
                    </a>
                </div>
            </div>

            <!-- Product Info -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Product</h3>
                <div class="space-y-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $service->product->name }}</p>
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">{{ ucfirst(str_replace('_', ' ', $service->product->type)) }}</p>
                    </div>
                    <a href="{{ route('admin.products.show', $service->product) }}" class="block px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                        View Product
                    </a>
                </div>
            </div>

            <!-- Related Invoice -->
            @if ($service->invoice)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Invoice</h3>
                    <div class="space-y-3">
                        <p class="text-sm text-slate-900 dark:text-white">Invoice #{{ str_pad($service->invoice->id, 5, '0', STR_PAD_LEFT) }}</p>
                        <a href="{{ route('admin.invoices.show', $service->invoice) }}" class="block px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                            View Invoice
                        </a>
                    </div>
                </div>
            @endif

            <!-- Timeline -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Timeline</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-slate-900 dark:text-white">{{ $service->created_at->format('M d, Y \a\t h:i A') }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Last Updated</p>
                        <p class="text-slate-900 dark:text-white">{{ $service->updated_at->format('M d, Y \a\t h:i A') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Details Modal -->
    <div x-show="editDetailsModal" x-transition
         class="fixed inset-0 bg-black/50 z-50 flex items-end"
         @click.self="editDetailsModal = false">
        <div class="bg-white dark:bg-slate-900 w-full max-w-2xl rounded-t-2xl shadow-2xl overflow-y-auto max-h-screen">
            <!-- Sticky Header -->
            <div class="sticky top-0 flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 z-10">
                <h2 class="text-xl font-bold text-slate-900 dark:text-white">Edit Service Details</h2>
                <button @click="editDetailsModal = false" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Form Content -->
            <form method="POST" action="{{ route('admin.services.update', $service) }}" class="p-6 space-y-6">
                @csrf
                @method('PUT')

                <!-- Billing Cycle -->
                <div>
                    <label for="billing_cycle" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing Cycle</label>
                    <select id="billing_cycle" name="billing_cycle"
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            required>
                        <option value="monthly" @selected($service->billing_cycle === 'monthly')>Monthly</option>
                        <option value="quarterly" @selected($service->billing_cycle === 'quarterly')>Quarterly</option>
                        <option value="semi-annual" @selected($service->billing_cycle === 'semi-annual')>Semi-Annual</option>
                        <option value="annual" @selected($service->billing_cycle === 'annual')>Annual</option>
                    </select>
                </div>

                <!-- Next Due Date -->
                <div>
                    <label for="next_due_date" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Next Due Date</label>
                    <input type="date" id="next_due_date" name="next_due_date"
                           value="{{ $service->next_due_date?->format('Y-m-d') }}"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           required>
                </div>

                <!-- Commenced At -->
                <div>
                    <label for="commenced_at" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Commenced At</label>
                    <input type="date" id="commenced_at" name="commenced_at"
                           value="{{ $service->commenced_at?->format('Y-m-d') }}"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <!-- Suspend Date -->
                <div>
                    <label for="suspend_date" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Suspended</label>
                    <input type="date" id="suspend_date" name="suspend_date"
                           value="{{ $service->suspend_date?->format('Y-m-d') }}"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <!-- Terminate Date -->
                <div>
                    <label for="terminate_date" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Terminated</label>
                    <input type="date" id="terminate_date" name="terminate_date"
                           value="{{ $service->terminate_date?->format('Y-m-d') }}"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <!-- Notes -->
                <div>
                    <label for="notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes</label>
                    <textarea id="notes" name="notes" rows="4"
                              class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                              placeholder="Add any notes about this service...">{{ $service->notes }}</textarea>
                </div>

                <!-- Form Actions (Sticky Footer) -->
                <div class="flex gap-3 pt-6 border-t border-slate-200 dark:border-slate-800 sticky bottom-0 bg-white dark:bg-slate-900">
                    <button type="button" @click="editDetailsModal = false"
                            class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 font-medium transition">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
