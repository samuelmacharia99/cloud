@extends('layouts.admin')

@section('title', 'SMS Notifications')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">SMS Notifications</p>
@endsection

@section('content')
<div class="space-y-6" x-data="smsManager()">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">SMS Notifications</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Send SMS messages to customers and broadcast announcements.</p>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Sent Today</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $totalSentToday }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Failed Today</p>
            <p class="text-3xl font-bold text-red-600 dark:text-red-400 mt-2">{{ $totalFailedToday }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Sent</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $totalAllTime }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Compose Section -->
        <div class="lg:col-span-2">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-6">
                <h2 class="text-xl font-bold text-slate-900 dark:text-white">Compose Message</h2>

                <form action="{{ route('admin.sms.send') }}" method="POST" @submit="submitForm">
                    @csrf

                    <!-- Recipient Type -->
                    <div class="space-y-3 mb-6">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="radio" name="recipient_type" value="all" @change="recipientType = 'all'" :checked="recipientType === 'all'" class="rounded-full border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">All Active Customers</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="radio" name="recipient_type" value="custom" @change="recipientType = 'custom'" :checked="recipientType === 'custom'" class="rounded-full border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Select Specific Customers</span>
                        </label>
                    </div>

                    <!-- Customer Selection (shown when custom selected) -->
                    <div x-show="recipientType === 'custom'" class="mb-6 space-y-3">
                        <!-- Search Input -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search Customers</label>
                            <input
                                type="text"
                                x-model="searchQuery"
                                @input="filterCustomers()"
                                placeholder="Search by name or email..."
                                class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            >
                        </div>

                        <!-- Customers List -->
                        <div class="max-h-48 overflow-y-auto border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
                            <template x-if="filteredCustomers.length === 0">
                                <div class="p-4 text-center text-slate-500 dark:text-slate-400">
                                    <p x-show="searchQuery">No customers found matching your search</p>
                                    <p x-show="!searchQuery">No customers available</p>
                                </div>
                            </template>

                            <template x-if="filteredCustomers.length > 0">
                                <div>
                                    <template x-for="customer in filteredCustomers" :key="customer.id">
                                        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-700 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer transition-colors" @click="toggleCustomer(customer)">
                                            <label class="flex items-center gap-3 cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    :name="'recipients[]'"
                                                    :value="customer.id"
                                                    :checked="isSelected(customer.id)"
                                                    @change="toggleCustomer(customer)"
                                                    class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500"
                                                >
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-slate-900 dark:text-white">{{ customer.name }}</p>
                                                    <p class="text-xs text-slate-600 dark:text-slate-400 truncate">{{ customer.email }}</p>
                                                    <p class="text-xs text-slate-500 dark:text-slate-500">{{ customer.phone }}</p>
                                                </div>
                                            </label>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <!-- Selected Customers Display -->
                        <div x-show="selectedCustomers.length > 0" class="pt-3 border-t border-slate-200 dark:border-slate-700">
                            <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Selected Customers (<span x-text="selectedCustomers.length"></span>)</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="customerId in selectedCustomers" :key="customerId">
                                    <template x-if="getCustomerById(customerId)">
                                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 text-sm">
                                            <span x-text="getCustomerById(customerId).name"></span>
                                            <button
                                                type="button"
                                                @click="toggleCustomer(getCustomerById(customerId))"
                                                class="ml-1 hover:bg-blue-200 dark:hover:bg-blue-800 rounded-full p-0.5"
                                            >
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                        </div>
                                    </template>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Message -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Message</label>
                        <textarea
                            name="message"
                            x-model="message"
                            @input="charCount = message.length"
                            maxlength="160"
                            required
                            class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 resize-none h-24"
                            placeholder="Type your SMS message..."
                        ></textarea>
                        <div class="flex justify-between items-center mt-2">
                            <p class="text-xs text-slate-600 dark:text-slate-400">Max 160 characters</p>
                            <span class="text-xs font-medium" :class="charCount > 150 ? 'text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-slate-400'">
                                <span x-text="charCount"></span>/160
                            </span>
                        </div>
                    </div>

                    <!-- Submit -->
                    <button
                        type="submit"
                        @click="
                            const count = recipientType === 'all' ? {{ $customers->count() }} : selectedCustomers.length;
                            if (!confirm('Send SMS to ' + count + ' customer(s)?')) {
                                event.preventDefault();
                            }
                        "
                        class="w-full px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="recipientType === 'custom' && selectedCustomers.length === 0"
                    >
                        <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8m0 8l-9-2m9 2l9-2m-9-8l9 2m-9-2l-9 2"/>
                        </svg>
                        Send SMS
                    </button>
                </form>
            </div>
        </div>

        <!-- Recent Logs -->
        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Recent Logs</h2>

                <div class="space-y-3 max-h-96 overflow-y-auto">
                    @forelse ($logs->items() as $log)
                        <div class="pb-3 border-b border-slate-200 dark:border-slate-700 last:border-0 last:pb-0">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-medium text-slate-600 dark:text-slate-400">
                                        {{ $log->created_at->format('H:i') }}
                                    </p>
                                    <p class="text-sm text-slate-900 dark:text-white truncate">
                                        {{ $log->recipient }}
                                    </p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 line-clamp-2">
                                        {{ substr($log->message, 0, 50) }}{{ strlen($log->message) > 50 ? '...' : '' }}
                                    </p>
                                </div>
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium flex-shrink-0 {{ $log->status === 'sent' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' }}">
                                    {{ ucfirst($log->status) }}
                                </span>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500 dark:text-slate-400 text-center py-4">No logs yet</p>
                    @endforelse
                </div>

                <!-- Pagination Links -->
                @if ($logs->hasPages())
                    <div class="mt-4 pt-4 border-t border-slate-200 dark:border-slate-700">
                        <div class="flex justify-between items-center text-xs text-slate-600 dark:text-slate-400">
                            <span>Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>
                            <div class="flex gap-1">
                                @if ($logs->onFirstPage())
                                    <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 text-slate-400 rounded">← Prev</span>
                                @else
                                    <a href="{{ $logs->previousPageUrl() }}" class="px-2 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 rounded hover:bg-blue-200 dark:hover:bg-blue-800">← Prev</a>
                                @endif

                                @if ($logs->hasMorePages())
                                    <a href="{{ $logs->nextPageUrl() }}" class="px-2 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 rounded hover:bg-blue-200 dark:hover:bg-blue-800">Next →</a>
                                @else
                                    <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 text-slate-400 rounded">Next →</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
function smsManager() {
    return {
        recipientType: 'all',
        message: '',
        charCount: 0,
        searchQuery: '',
        selectedCustomers: [],
        allCustomers: {!! json_encode($customers) !!},
        filteredCustomers: [],

        init() {
            this.filteredCustomers = [...this.allCustomers];
        },

        filterCustomers() {
            const query = this.searchQuery.toLowerCase().trim();
            if (!query) {
                this.filteredCustomers = [...this.allCustomers];
            } else {
                this.filteredCustomers = this.allCustomers.filter(customer =>
                    customer.name.toLowerCase().includes(query) ||
                    customer.email.toLowerCase().includes(query) ||
                    customer.phone.includes(query)
                );
            }
        },

        isSelected(customerId) {
            return this.selectedCustomers.includes(customerId);
        },

        toggleCustomer(customer) {
            const index = this.selectedCustomers.indexOf(customer.id);
            if (index > -1) {
                this.selectedCustomers.splice(index, 1);
            } else {
                this.selectedCustomers.push(customer.id);
            }
        },

        getCustomerById(customerId) {
            return this.allCustomers.find(c => c.id === customerId);
        },

        submitForm(e) {
            if (this.recipientType === 'custom' && this.selectedCustomers.length === 0) {
                e.preventDefault();
                alert('Please select at least one customer');
            }
        }
    }
}
</script>
@endsection
