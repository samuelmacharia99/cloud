@extends('layouts.customer')

@section('title', 'My Domains')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Domains</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your domain registrations</p>
        </div>
    </div>

    <!-- Registered Domains Section -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="p-6 border-b border-slate-200 dark:border-slate-800">
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">My Registered Domains</h2>
        </div>

        @if($domains->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Domain</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Registered</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Expires</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        @foreach($domains as $domain)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">
                                    {{ $domain->name }}{{ $domain->extension }}
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                    {{ $domain->registered_at?->format('M d, Y') ?? 'N/A' }}
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                    {{ $domain->expires_at?->format('M d, Y') ?? 'N/A' }}
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium {{ $domain->status === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300' }}">
                                        {{ ucfirst($domain->status) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="p-8 text-center">
                <svg class="w-12 h-12 mx-auto text-slate-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                </svg>
                <p class="text-slate-500 dark:text-slate-400">You haven't registered any domains yet</p>
            </div>
        @endif
    </div>

    <!-- Domain Search & Register Section -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
        <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Register New Domain</h2>

        <div x-data="{ searchQuery: '', searching: false, results: [], showResults: false }" class="space-y-4">
            <!-- Search Form -->
            <div class="flex gap-2">
                <input
                    type="text"
                    x-model="searchQuery"
                    placeholder="Search for a domain (e.g., mysite.com)"
                    @keyup.debounce.1500="
                        if(searchQuery.length > 2) {
                            searching = true;
                            fetch(`{{ route('domains.search') }}?q=${encodeURIComponent(searchQuery)}`)
                                .then(r => r.json())
                                .then(data => {
                                    results = data.results;
                                    showResults = true;
                                    searching = false;
                                });
                        } else {
                            showResults = false;
                        }
                    "
                    class="flex-1 px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                />
                <button
                    type="button"
                    @click="
                        if(searchQuery.length > 0) {
                            searching = true;
                            fetch(`{{ route('domains.search') }}?q=${encodeURIComponent(searchQuery)}`)
                                .then(r => r.json())
                                .then(data => {
                                    results = data.results;
                                    showResults = true;
                                    searching = false;
                                });
                        }
                    "
                    class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition"
                >
                    <span x-show="!searching">Check Availability</span>
                    <span x-show="searching" class="inline-flex items-center gap-2">
                        <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Searching...
                    </span>
                </button>
            </div>

            <!-- Results -->
            <div x-show="showResults && results.length > 0" class="mt-6 space-y-3">
                <h3 class="font-semibold text-slate-900 dark:text-white">Available Domains</h3>
                <div class="grid gap-3">
                    <template x-for="result in results" :key="`${result.domain}${result.extension}`">
                        <div class="flex items-center justify-between p-4 rounded-lg border" :class="result.available ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800'">
                            <div>
                                <p class="font-semibold text-slate-900 dark:text-white" x-text="`${result.full_domain}`"></p>
                                <p class="text-sm" :class="result.available ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300'" x-text="result.available ? '✓ Available' : '✗ Taken'"></p>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold text-slate-900 dark:text-white" x-text="`Ksh ${result.price.toLocaleString()}`"></p>
                                <template x-if="result.available">
                                    <form action="{{ route('customer.cart.add') }}" method="POST" class="mt-2">
                                        @csrf
                                        <input type="hidden" name="type" value="domain">
                                        <input type="hidden" name="domain" :value="result.domain">
                                        <input type="hidden" name="extension" :value="result.extension">
                                        <select name="years" class="px-3 py-1 text-sm rounded border border-green-300 dark:border-green-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                                            <option value="1">1 year</option>
                                            <option value="2">2 years</option>
                                            <option value="3">3 years</option>
                                            <option value="5">5 years</option>
                                        </select>
                                        <button type="submit" class="block w-full mt-2 px-3 py-1 bg-green-600 hover:bg-green-700 text-white text-sm rounded font-medium transition">
                                            Add to Cart
                                        </button>
                                    </form>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- No Results -->
            <div x-show="showResults && results.length === 0 && !searching" class="text-center py-6 text-slate-500 dark:text-slate-400">
                <p>No domains found matching your search</p>
            </div>
        </div>
    </div>
</div>
@endsection
