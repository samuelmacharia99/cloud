@extends('layouts.customer')

@section('title', 'Confirm & continue')

@section('content')
<div class="space-y-6 max-w-3xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Almost ready</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">We pick the resources for you. You only need a domain — email is included.</p>
    </div>

    @if(!empty($attachDomain))
        <div class="bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 rounded-xl p-4 text-sm text-blue-900 dark:text-blue-100">
            <p class="font-semibold">Linked domain: {{ $attachDomain['fqdn'] }}</p>
            <p class="mt-1">We’ll use this domain for your app and email.</p>
        </div>
    @endif

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-4">
        <h2 class="font-semibold text-slate-900 dark:text-white">Your selection</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div>
                <p class="text-xs text-slate-500">Stack</p>
                <p class="font-medium text-slate-900 dark:text-white">{{ $language->name }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500">Database</p>
                <p class="font-medium text-slate-900 dark:text-white">{{ $database?->name ?? 'None' }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500">Billing</p>
                <p class="font-medium text-slate-900 dark:text-white">Monthly usage (starter + meters)</p>
            </div>
        </div>
        <a href="{{ route('customer.select-techstack') }}" class="text-sm text-blue-600 dark:text-blue-400 hover:underline">Change stack →</a>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
            <div>
                <h2 class="font-semibold text-slate-900 dark:text-white">Monthly starter</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Includes the allotment below. Extra usage is billed on renewal.</p>
            </div>
            <p class="text-3xl font-bold text-blue-600 dark:text-blue-400">
                {{ $currency?->symbol ?? 'KES' }} {{ number_format($floorPrice * ($currency?->exchange_rate ?? 1), 0) }}
                <span class="text-sm font-normal text-slate-500">/ month</span>
            </p>
        </div>

        <ul class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm mb-6">
            <li class="rounded-lg bg-slate-50 dark:bg-slate-800 p-3">
                <p class="text-xs text-slate-500">CPU</p>
                <p class="font-semibold text-slate-900 dark:text-white">{{ $included['cpu'] }} cores</p>
            </li>
            <li class="rounded-lg bg-slate-50 dark:bg-slate-800 p-3">
                <p class="text-xs text-slate-500">Memory</p>
                <p class="font-semibold text-slate-900 dark:text-white">{{ number_format($included['memory_mb'] / 1024, 1) }} GB</p>
            </li>
            <li class="rounded-lg bg-slate-50 dark:bg-slate-800 p-3">
                <p class="text-xs text-slate-500">Disk</p>
                <p class="font-semibold text-slate-900 dark:text-white">{{ $included['disk_gb'] }} GB</p>
            </li>
            <li class="rounded-lg bg-slate-50 dark:bg-slate-800 p-3">
                <p class="text-xs text-slate-500">Mailboxes</p>
                <p class="font-semibold text-slate-900 dark:text-white">{{ $included['mailboxes'] }} included</p>
            </li>
        </ul>

        @if($autoEmail)
            <p class="text-sm text-emerald-700 dark:text-emerald-300 mb-4">
                Email for this domain is included by default{{ $emailProduct ? ' ('.$emailProduct->name.')' : '' }}.
            </p>
        @endif

        <form method="POST" action="{{ route('customer.confirm-techstack.usage') }}" class="space-y-4">
            @csrf
            <div>
                <label for="primary_domain" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Primary domain</label>
                <input
                    type="text"
                    name="primary_domain"
                    id="primary_domain"
                    value="{{ old('primary_domain', $attachDomain['fqdn'] ?? '') }}"
                    placeholder="example.com"
                    required
                    class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500"
                >
                @error('primary_domain')
                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
                <p class="text-xs text-slate-500 mt-1">Used for your app and email. You can connect DNS after checkout.</p>
            </div>

            @if(!empty($language->versions) && is_array($language->versions) && count($language->versions) > 0)
                <div>
                    <label for="selected_version" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Runtime version (optional)</label>
                    <select name="selected_version" id="selected_version" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        <option value="">Default</option>
                        @foreach($language->versions as $version)
                            <option value="{{ is_array($version) ? ($version['value'] ?? $version['label'] ?? '') : $version }}" @selected(old('selected_version') == (is_array($version) ? ($version['value'] ?? '') : $version))>
                                {{ is_array($version) ? ($version['label'] ?? $version['value'] ?? '') : $version }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <button type="submit" class="w-full sm:w-auto px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                Continue to checkout
            </button>
        </form>
    </div>
</div>
@endsection
