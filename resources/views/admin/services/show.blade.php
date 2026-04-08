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
<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Service #{{ $service->id }}</h1>
                <p class="text-slate-600 dark:text-slate-400 mt-2">{{ $service->product->name }} • {{ ucfirst(str_replace('_', ' ', $service->product->type)) }}</p>

                <!-- Status badge -->
                <div class="mt-4">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if($service->status === 'active')
                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                        @elseif($service->status === 'pending')
                            bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                        @elseif($service->status === 'provisioning')
                            bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                        @elseif($service->status === 'suspended')
                            bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300
                        @elseif($service->status === 'terminated')
                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                        @elseif($service->status === 'failed')
                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                        @endif
                    ">
                        {{ ucfirst($service->status) }}
                    </span>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2 flex-wrap">
                @if ($service->status === 'pending')
                    <form method="POST" action="{{ route('admin.services.provision', $service) }}" class="inline">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                            Provision
                        </button>
                    </form>
                @endif

                @if ($service->status === 'active')
                    <form method="POST" action="{{ route('admin.services.suspend', $service) }}" class="inline">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition text-sm">
                            Suspend
                        </button>
                    </form>
                @endif

                @if ($service->status === 'suspended')
                    <form method="POST" action="{{ route('admin.services.unsuspend', $service) }}" class="inline">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition text-sm">
                            Unsuspend
                        </button>
                    </form>
                @endif

                @if (in_array($service->status, ['active', 'suspended', 'pending']))
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
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Service Details</h2>
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Status</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ ucfirst($service->status) }}</p>
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
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Configuration</h2>
                <div class="space-y-3">
                    @if ($service->provisioning_driver_key)
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Provisioning Driver</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $service->provisioning_driver_key }}</p>
                        </div>
                    @endif
                    @if ($service->external_reference)
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">External Reference</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $service->external_reference }}</p>
                        </div>
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
</div>
@endsection
