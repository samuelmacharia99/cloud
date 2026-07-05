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
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Services</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage customer services and subscriptions.</p>
            @if (($mismatchCount ?? 0) > 0)
                <p class="mt-2 text-sm text-amber-600 dark:text-amber-400">
                    {{ $mismatchCount }} service(s) have platform vs infrastructure status drift.
                    <a href="{{ request()->fullUrlWithQuery(['mismatch' => 1]) }}" class="underline font-medium">Show mismatches</a>
                </p>
            @endif
        </div>
        <form method="POST" action="{{ route('admin.services.refresh-live-status') }}" class="flex flex-wrap gap-2">
            @csrf
            @foreach (request()->query() as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <button type="submit" class="px-4 py-2 bg-slate-700 hover:bg-slate-800 text-white text-sm font-medium rounded-lg transition">
                Refresh live status (page)
            </button>
        </form>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Service #, customer, domain..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
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
                    <option value="ssl" @selected(request('type') === 'ssl')>SSL Certificate</option>
                    <option value="email_hosting" @selected(request('type') === 'email_hosting')>Email Hosting</option>
                    <option value="sms_bundle" @selected(request('type') === 'sms_bundle')>SMS Bundle</option>
                    <option value="hotspot_plan" @selected(request('type') === 'hotspot_plan')>Hotspot Plan</option>
                </select>
            </div>
            <div class="flex items-end gap-3">
                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 pb-2">
                    <input type="checkbox" name="mismatch" value="1" @checked(request()->boolean('mismatch')) class="rounded border-slate-300 dark:border-slate-600">
                    Drift only
                </label>
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">Filter</button>
            </div>
        </div>
    </form>

    <!-- Services list -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-600 dark:text-slate-400">
                <span class="font-semibold text-slate-900 dark:text-white">{{ $services->total() }}</span> service(s)
                @if (request()->boolean('mismatch'))
                    <span class="text-amber-600 dark:text-amber-400">· drift filter on</span>
                @endif
            </p>
            <p class="text-xs text-slate-500 dark:text-slate-400 hidden sm:block">
                Platform = billing record · Live = DirectAdmin / Docker
            </p>
        </div>

        <div class="ui-table-wrap">
            <table class="ui-table min-w-[880px]">
                <thead>
                    <tr>
                        <th class="min-w-[200px]">Service</th>
                        <th class="min-w-[180px]">Customer</th>
                        <th class="whitespace-nowrap">Billing</th>
                        <th class="min-w-[160px]">Status</th>
                        <th class="text-right w-28 sticky right-0 bg-slate-50/95 dark:bg-slate-800/95 backdrop-blur-sm">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($services as $service)
                        <tr class="{{ $service->live_status_mismatch ? 'bg-amber-50/40 dark:bg-amber-950/10' : '' }}">
                            <td>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('admin.services.show', $service) }}" class="font-semibold text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400">
                                            #{{ $service->id }}
                                        </a>
                                        <span class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ $service->product->name }}</span>
                                    </div>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 capitalize">
                                        {{ str_replace('_', ' ', $service->product->type) }}
                                    </p>
                                </div>
                            </td>
                            <td>
                                <div class="min-w-0">
                                    <x-admin.customer-link :user="$service->user" class="truncate text-slate-900 dark:text-white" />
                                    <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ $service->user->email }}</p>
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                <p class="text-sm text-slate-800 dark:text-slate-200 capitalize">{{ $service->billing_cycle }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    Due {{ $service->next_due_date?->format('M j, Y') ?? '—' }}
                                </p>
                            </td>
                            <td>
                                <div class="flex flex-col gap-1.5 items-start">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 w-14 shrink-0">Platform</span>
                                        @include('admin.services.partials.platform-status-badge', ['service' => $service])
                                    </div>
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 w-14 shrink-0">Live</span>
                                        @include('admin.services.partials.live-status-badge', ['service' => $service, 'compact' => true])
                                    </div>
                                </div>
                            </td>
                            <td class="text-right sticky right-0 bg-white dark:bg-slate-900 {{ $service->live_status_mismatch ? 'bg-amber-50/40 dark:bg-amber-950/10' : '' }}">
                                @include('admin.services.partials.row-actions', ['service' => $service])
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-16 text-center">
                                <p class="text-slate-600 dark:text-slate-400">No services found.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($services->hasPages())
            <div class="px-5 py-4 border-t border-slate-200 dark:border-slate-800">
                {{ $services->links() }}
            </div>
        @endif
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
