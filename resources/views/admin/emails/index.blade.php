@extends('layouts.admin')

@section('title', 'Emails')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Emails</p>
@endsection

@section('content')
<div class="space-y-6" x-data="emailBroadcastManager()">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Emails</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Send announcements to platform customers and review the email log.</p>
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
        <!-- Compose -->
        <div class="lg:col-span-2">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-6">
                <div>
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white">Compose broadcast</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                        Sends to platform customers only (not resellers or reseller-managed customers).
                        Emails go out one at a time, 5 seconds apart, to reduce spam flagging.
                    </p>
                </div>

                <form action="{{ route('admin.emails.send') }}" method="POST" @submit="submitForm">
                    @csrf

                    <div class="space-y-3 mb-6">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="radio" name="recipient_type" value="all" @change="recipientType = 'all'" :checked="recipientType === 'all'" class="rounded-full border-slate-300 dark:border-slate-600 focus:ring-teal-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">
                                All platform customers
                                <span class="text-slate-500 font-normal">({{ $customers->count() }})</span>
                            </span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="radio" name="recipient_type" value="custom" @change="recipientType = 'custom'" :checked="recipientType === 'custom'" class="rounded-full border-slate-300 dark:border-slate-600 focus:ring-teal-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Select specific customers</span>
                        </label>
                    </div>

                    <div x-show="recipientType === 'custom'" x-cloak class="mb-6 space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search customers</label>
                            <input
                                type="text"
                                x-model="searchQuery"
                                @input="filterCustomers()"
                                placeholder="Search by name or email..."
                                class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-teal-500 focus:ring-1 focus:ring-teal-500"
                            >
                        </div>

                        <div class="max-h-48 overflow-y-auto border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
                            <template x-if="filteredCustomers.length === 0">
                                <div class="p-4 text-center text-slate-500 dark:text-slate-400 text-sm">
                                    <p x-show="searchQuery">No customers match your search</p>
                                    <p x-show="!searchQuery">No platform customers available</p>
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
                                                    @click.stop
                                                    @change="toggleCustomer(customer)"
                                                    class="rounded border-slate-300 dark:border-slate-600 focus:ring-teal-500"
                                                >
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-slate-900 dark:text-white" x-text="customer.name"></p>
                                                    <p class="text-xs text-slate-600 dark:text-slate-400 truncate" x-text="customer.email"></p>
                                                </div>
                                            </label>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <div x-show="selectedCustomers.length > 0" class="pt-3 border-t border-slate-200 dark:border-slate-700">
                            <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                                Selected (<span x-text="selectedCustomers.length"></span>)
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="customerId in selectedCustomers" :key="customerId">
                                    <template x-if="getCustomerById(customerId)">
                                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 dark:bg-teal-900 text-teal-800 dark:text-teal-200 text-sm">
                                            <span x-text="getCustomerById(customerId).name"></span>
                                            <button
                                                type="button"
                                                @click="toggleCustomer(getCustomerById(customerId))"
                                                class="ml-1 hover:bg-teal-200 dark:hover:bg-teal-800 rounded-full p-0.5"
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

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Subject</label>
                        <input
                            type="text"
                            name="subject"
                            value="{{ old('subject') }}"
                            required
                            maxlength="200"
                            class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-teal-500 focus:ring-1 focus:ring-teal-500 @error('subject') border-red-500 @enderror"
                            placeholder="Maintenance window this weekend"
                        >
                        @error('subject')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Message</label>
                        <textarea
                            name="body"
                            required
                            maxlength="10000"
                            rows="8"
                            class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-teal-500 focus:ring-1 focus:ring-teal-500 resize-y @error('body') border-red-500 @enderror"
                            placeholder="Write your announcement…"
                        >{{ old('body') }}</textarea>
                        @error('body')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Plain text; line breaks are preserved in the email.</p>
                    </div>

                    <button
                        type="submit"
                        @click.prevent="
                            (async () => {
                                const count = recipientType === 'all' ? {{ $customers->count() }} : selectedCustomers.length;
                                if (count < 1) {
                                    await window.appAlert('Select at least one customer.', 'No recipients');
                                    return;
                                }
                                if (await window.appConfirm('Send email to ' + count + ' platform customer(s)?', 'Send broadcast', 'Send')) {
                                    $el.closest('form').requestSubmit();
                                }
                            })();
                        "
                        class="w-full px-6 py-2.5 bg-teal-600 hover:bg-teal-700 text-white font-medium rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="recipientType === 'custom' && selectedCustomers.length === 0"
                    >
                        Send email
                    </button>
                </form>
            </div>
        </div>

        <!-- Filters sidebar-ish -->
        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Log filters</h2>
                <div class="flex flex-col gap-2">
                    <a href="{{ route('admin.emails.index') }}" class="px-4 py-2 rounded-lg font-medium text-sm transition-all {{ $status === 'all' ? 'bg-teal-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                        All emails
                    </a>
                    <a href="{{ route('admin.emails.index', ['status' => 'sent']) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition-all {{ $status === 'sent' ? 'bg-green-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                        Sent
                    </a>
                    <a href="{{ route('admin.emails.index', ['status' => 'failed']) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition-all {{ $status === 'failed' ? 'bg-red-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                        Failed
                    </a>
                    <a href="{{ route('admin.emails.index', ['status' => 'bounced']) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition-all {{ $status === 'bounced' ? 'bg-amber-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                        Bounced
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Email Table -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700">
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Email log</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Recipient</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Subject</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Sent By</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    @forelse ($emails as $email)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4 text-sm text-slate-900 dark:text-white">{{ $email->recipient }}</td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300 truncate max-w-xs">{{ $email->subject }}</td>
                            <td class="px-6 py-4 text-sm">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium {{ $email->status === 'sent' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : ($email->status === 'failed' ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300') }}">
                                    {{ ucfirst($email->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                {{ $email->sentBy?->name ?? 'System' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                {{ $email->created_at->format('M d, Y H:i') }}
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <a href="{{ route('admin.emails.show', $email) }}" class="text-teal-600 dark:text-teal-400 hover:text-teal-700 dark:hover:text-teal-300 font-medium">
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
                                <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                                <p class="text-sm font-medium">No emails found</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($emails->hasPages())
            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-700">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-slate-600 dark:text-slate-400">
                        Showing <span class="font-medium">{{ $emails->firstItem() }}</span> to <span class="font-medium">{{ $emails->lastItem() }}</span> of <span class="font-medium">{{ $emails->total() }}</span> emails
                    </div>
                    <div class="flex gap-2">
                        @if ($emails->onFirstPage())
                            <span class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Previous</span>
                        @else
                            <a href="{{ $emails->previousPageUrl() }}" class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-700">← Previous</a>
                        @endif

                        @if ($emails->hasMorePages())
                            <a href="{{ $emails->nextPageUrl() }}" class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-700">Next →</a>
                        @else
                            <span class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</span>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>

<script>
function emailBroadcastManager() {
    return {
        recipientType: 'all',
        searchQuery: '',
        selectedCustomers: [],
        allCustomers: {{ Js::from($customers) }},
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
                    (customer.name || '').toLowerCase().includes(query) ||
                    (customer.email || '').toLowerCase().includes(query)
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
            }
        }
    }
}
</script>
@endsection
