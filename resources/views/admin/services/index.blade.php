@extends('layouts.admin')

@section('title', 'Services')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Services</p>
@endsection

@section('content')
<div class="space-y-6" x-data="{
    editOpen: false,
    editId: null,
    editStatus: '',
    editBillingCycle: '',
    editNextDue: '',
    editCommencedAt: '',
    editUsername: '',
    editPassword: '',
    showPassword: false,
    openEdit(id, status, billingCycle, nextDue, commencedAt, username, password) {
        this.editId = id;
        this.editStatus = status;
        this.editBillingCycle = billingCycle;
        this.editNextDue = nextDue;
        this.editCommencedAt = commencedAt;
        this.editUsername = username;
        this.editPassword = password;
        this.editOpen = true;
    }
}">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Services</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Manage customer services and subscriptions.</p>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Service #, customer..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <option value="all">All Status</option>
                    <option value="pending" @selected(request('status') === 'pending')>Pending</option>
                    <option value="provisioning" @selected(request('status') === 'provisioning')>Provisioning</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="suspended" @selected(request('status') === 'suspended')>Suspended</option>
                    <option value="terminated" @selected(request('status') === 'terminated')>Terminated</option>
                    <option value="failed" @selected(request('status') === 'failed')>Failed</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Product Type</label>
                <select name="type" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <option value="all">All Types</option>
                    <option value="shared_hosting" @selected(request('type') === 'shared_hosting')>Shared Hosting</option>
                    <option value="container_hosting" @selected(request('type') === 'container_hosting')>Container Hosting</option>
                    <option value="domain" @selected(request('type') === 'domain')>Domain</option>
                    <option value="ssl" @selected(request('type') === 'ssl')>SSL Certificate</option>
                    <option value="email_hosting" @selected(request('type') === 'email_hosting')>Email Hosting</option>
                    <option value="sms_bundle" @selected(request('type') === 'sms_bundle')>SMS Bundle</option>
                    <option value="hotspot_plan" @selected(request('type') === 'hotspot_plan')>Hotspot Plan</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">Filter</button>
            </div>
        </div>
    </form>

    <!-- Services Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Service ID</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Customer</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Product</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Billing Cycle</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Next Due</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($services as $service)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">#{{ $service->id }}</td>
                            <td class="px-6 py-4">
                                <div>
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $service->user->name }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">{{ $service->user->email }}</p>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div>
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $service->product->name }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">{{ ucfirst(str_replace('_', ' ', $service->product->type)) }}</p>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ ucfirst($service->billing_cycle) }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    @if($service->status->value === 'active')
                                        bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                                    @elseif($service->status->value === 'pending')
                                        bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                                    @elseif($service->status->value === 'provisioning')
                                        bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                                    @elseif($service->status->value === 'suspended')
                                        bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300
                                    @elseif($service->status->value === 'terminated')
                                        bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                    @elseif($service->status->value === 'failed')
                                        bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                    @endif
                                ">
                                    {{ ucfirst($service->status->value) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                {{ $service->next_due_date?->format('M d, Y') ?? '-' }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.services.show', $service) }}" class="px-3 py-1.5 text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                                        View
                                    </a>

                                    @php
                                        $credentials = is_string($service->credentials) ? json_decode($service->credentials, true) : ($service->credentials ?? []);
                                        $credUsername = $credentials['username'] ?? '';
                                        $credPassword = $credentials['password'] ?? '';
                                    @endphp

                                    <button @click="openEdit({{ $service->id }}, '{{ $service->status->value }}', '{{ addslashes($service->billing_cycle) }}', '{{ $service->next_due_date?->format('Y-m-d') }}', '{{ $service->commenced_at?->format('Y-m-d') }}', '{{ addslashes($credUsername) }}', '{{ addslashes($credPassword) }}')" class="px-3 py-1.5 text-sm font-medium text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300 transition">
                                        Edit
                                    </button>

                                    @if($service->status->value === 'suspended')
                                        <form method="POST" action="{{ route('admin.services.unsuspend', $service) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 text-sm font-medium text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300 transition">
                                                Unsuspend
                                            </button>
                                        </form>
                                    @elseif(in_array($service->status->value, ['active', 'pending', 'provisioning', 'failed']))
                                        <form method="POST" action="{{ route('admin.services.suspend', $service) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 text-sm font-medium text-orange-600 dark:text-orange-400 hover:text-orange-700 dark:hover:text-orange-300 transition">
                                                Suspend
                                            </button>
                                        </form>
                                    @endif

                                    <form method="POST" action="{{ route('admin.services.destroy', $service) }}" class="inline" onsubmit="return confirm('Delete service #{{ $service->id }}? This cannot be undone.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-3 py-1.5 text-sm font-medium text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 transition">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <p class="text-slate-600 dark:text-slate-400">No services found.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        {{ $services->links() }}
    </div>

    <!-- Edit Service Modal -->
    <div x-show="editOpen" x-transition class="fixed inset-0 bg-black/50 dark:bg-black/70 z-40" @click="editOpen = false" style="display: none;"></div>
    <div x-show="editOpen" x-transition class="fixed right-0 top-0 bottom-0 w-full max-w-md bg-white dark:bg-slate-900 shadow-2xl z-50 overflow-y-auto" style="display: none;">
        <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between sticky top-0 bg-white dark:bg-slate-900">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Edit Service</h2>
            <button @click="editOpen = false" class="text-slate-400 dark:text-slate-600 hover:text-slate-600 dark:hover:text-slate-400">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form :action="`{{ url('admin/services') }}/${editId}`" method="POST" class="p-6 space-y-6">
            @csrf
            @method('PUT')

            <!-- Status -->
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
                <select x-model="editStatus" name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
                    <option value="">No Change</option>
                    @foreach(\App\Enums\ServiceStatus::cases() as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Billing Cycle -->
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Billing Cycle</label>
                <select x-model="editBillingCycle" name="billing_cycle" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="semi-annual">Semi-Annual</option>
                    <option value="annual">Annual</option>
                </select>
            </div>

            <!-- Commenced Date -->
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Commenced Date</label>
                <input type="date" x-model="editCommencedAt" name="commenced_at" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
            </div>

            <!-- Next Due Date -->
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Next Due Date</label>
                <input type="date" x-model="editNextDue" name="next_due_date" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
            </div>

            <!-- Username -->
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Username</label>
                <input type="text" x-model="editUsername" name="username" placeholder="Leave empty to keep current" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
            </div>

            <!-- Password with Toggle -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">Password</label>
                    <button type="button" @click="showPassword = !showPassword" class="text-xs text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300">
                        <span x-text="showPassword ? 'Hide' : 'Show'"></span>
                    </button>
                </div>
                <input :type="showPassword ? 'text' : 'password'" x-model="editPassword" name="password" placeholder="Leave empty to keep current" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
            </div>

            <!-- Buttons -->
            <div class="flex gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                <button type="button" @click="editOpen = false" class="flex-1 px-4 py-2 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition">
                    Cancel
                </button>
                <button type="submit" class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
