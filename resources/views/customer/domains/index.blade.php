@extends('layouts.customer')

@section('title', 'My Domains')

@section('content')
<div class="space-y-6">
    <x-page-header title="My Domains" description="Register, transfer, renew, and manage DNS for your domains.">
        <x-slot:actions>
            <a href="{{ route('customer.domains.transfer-form') }}" class="btn-success">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                Transfer domain
            </a>
        </x-slot:actions>
    </x-page-header>

    <!-- Domain search -->
    <div class="ui-card overflow-hidden">
        <div class="ui-card-header">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Register a new domain</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Check availability and add to cart</p>
        </div>
        <div class="ui-card-body">
            <div x-data="{ searchQuery: '', searching: false, results: [], showResults: false }" class="space-y-4">
                <div class="flex flex-col sm:flex-row gap-2">
                    <input type="text" x-model="searchQuery" placeholder="e.g. mybusiness.co.ke"
                        @keyup.debounce.1200="if(searchQuery.length > 2) { searching = true; fetch(`{{ route('domains.search') }}?q=${encodeURIComponent(searchQuery)}`).then(r => r.json()).then(d => { results = d.results; showResults = true; searching = false; }); } else { showResults = false; }"
                        class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-sm" />
                    <button type="button" @click="if(searchQuery.length > 0) { searching = true; fetch(`{{ route('domains.search') }}?q=${encodeURIComponent(searchQuery)}`).then(r => r.json()).then(d => { results = d.results; showResults = true; searching = false; }); }"
                        class="btn-primary shrink-0">
                        <span x-show="!searching">Check availability</span>
                        <span x-show="searching" x-cloak>Searching…</span>
                    </button>
                </div>

                <div x-show="showResults && results.length > 0" x-cloak class="space-y-3">
                    <template x-for="result in results" :key="`${result.domain}${result.extension}`">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-xl border"
                            :class="result.available ? 'bg-emerald-50/80 dark:bg-emerald-950/30 border-emerald-200 dark:border-emerald-800/60' : 'bg-slate-50 dark:bg-slate-800/50 border-slate-200 dark:border-slate-700'">
                            <div>
                                <p class="font-semibold text-slate-900 dark:text-white font-mono" x-text="result.full_domain"></p>
                                <p class="text-sm mt-0.5" :class="result.available ? 'text-emerald-700 dark:text-emerald-300' : 'text-slate-500'" x-text="result.available ? 'Available' : 'Taken'"></p>
                            </div>
                            <div class="flex items-center gap-3">
                                <p class="font-bold text-slate-900 dark:text-white" x-text="`KES ${result.price.toLocaleString()}`"></p>
                                <template x-if="result.available">
                                    <form action="{{ route('customer.cart.add') }}" method="POST" class="flex items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="type" value="domain">
                                        <input type="hidden" name="domain" :value="result.domain">
                                        <input type="hidden" name="extension" :value="result.extension">
                                        <select name="years" class="text-sm rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800">
                                            <option value="1">1 yr</option><option value="2">2 yr</option><option value="3">3 yr</option><option value="5">5 yr</option>
                                        </select>
                                        <button type="submit" class="btn-success btn-sm">Add to cart</button>
                                    </form>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <div x-show="showResults && results.length === 0 && !searching" x-cloak class="text-center py-8 text-sm text-slate-500">No matching domains found.</div>
            </div>
        </div>
    </div>

    <!-- Add domain for DNS management -->
    @if($cloudflareDnsAvailable ?? false)
        <div class="ui-card overflow-hidden">
            <div class="ui-card-header">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Add domain for DNS</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Already own a domain elsewhere? Add it here to manage DNS on Talksasa (Cloudflare). No transfer or registration fee.
                </p>
            </div>
            <div class="ui-card-body">
                <form method="POST" action="{{ route('customer.domains.dns.store') }}" class="flex flex-col sm:flex-row gap-2">
                    @csrf
                    <input
                        type="text"
                        name="domain"
                        value="{{ old('domain') }}"
                        placeholder="e.g. mybusiness.co.ke"
                        required
                        class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-sm @error('domain') border-red-500 @enderror"
                    >
                    <button type="submit" class="btn-primary shrink-0">Add &amp; enable DNS</button>
                </form>
                @error('domain')
                    <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    After adding, set your registrar nameservers to the ones we show on the DNS page. DNS records are then edited here — not in Cloudflare.
                </p>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-amber-200 dark:border-amber-800/60 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 text-sm text-amber-900 dark:text-amber-100">
            Managed DNS add is temporarily unavailable. You can still register or transfer domains.
        </div>
    @endif

    <!-- Registered domains -->
    <x-dashboard-section title="Registered domains" description="{{ $domains->count() }} domain(s) in your account">
        @if($domains->count() > 0)
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Registered</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($domains as $domain)
                            <tr>
                                <td>
                                    <div class="flex flex-col gap-1">
                                        <span class="font-semibold text-slate-900 dark:text-white font-mono text-sm">{{ $domain->name }}{{ $domain->extension }}</span>
                                        @if($domain->isDnsManaged())
                                            <span class="inline-flex w-fit px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide bg-sky-100 text-sky-800 dark:bg-sky-950/50 dark:text-sky-200">DNS only</span>
                                        @elseif($domain->isTransfer())
                                            <span class="inline-flex w-fit px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide bg-violet-100 text-violet-800 dark:bg-violet-950/50 dark:text-violet-200">Transfer</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-slate-600 dark:text-slate-400">{{ $domain->registered_at?->format('M d, Y') ?? '—' }}</td>
                                <td>
                                    <span class="{{ $domain->expires_at?->isPast() ? 'text-red-600 dark:text-red-400 font-medium' : '' }}">
                                        {{ $domain->expires_at?->format('M d, Y') ?? '—' }}
                                    </span>
                                </td>
                                <td><x-domain-status-badge :status="$domain->status" /></td>
                                <td class="text-right">
                                    <div x-data="{ open: false, showRenewal: false, years: 1 }" class="relative inline-block text-left">
                                        <button @click="open = !open" type="button" class="btn-ghost btn-sm" aria-label="Domain actions">
                                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                                        </button>
                                        <div x-show="open" @click.outside="open = false; showRenewal = false" x-cloak
                                            class="absolute right-0 mt-2 w-56 bg-white dark:bg-slate-900 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 z-50 overflow-hidden">
                                            <div x-show="!showRenewal">
                                                @unless($domain->isDnsManaged())
                                                    <button @click="showRenewal = true" type="button" class="w-full text-left px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800 transition flex items-center gap-3 border-b border-slate-100 dark:border-slate-800">
                                                        <span class="text-brand-600">↻</span>
                                                        <span class="font-medium text-sm">Renew domain</span>
                                                    </button>
                                                @endunless
                                                <a href="{{ route('customer.domains.dns.index', $domain) }}" class="block px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800 transition text-sm font-medium border-b border-slate-100 dark:border-slate-800">DNS management</a>
                                            </div>
                                            <div x-show="showRenewal" class="p-4">
                                                <button @click="showRenewal = false" type="button" class="text-xs font-semibold text-slate-500 mb-3">← Back</button>
                                                <p class="text-sm font-semibold mb-2">Renewal period</p>
                                                <div class="space-y-2 mb-4">
                                                    @foreach([1, 2, 3, 5] as $period)
                                                        <label class="flex items-center gap-2 p-2 rounded-lg border cursor-pointer text-sm" :class="years == {{ $period }} ? 'border-brand-400 bg-brand-50 dark:bg-brand-950/40' : 'border-slate-200 dark:border-slate-700'">
                                                            <input type="radio" :value="{{ $period }}" x-model.number="years" class="text-brand-600">
                                                            {{ $period }} year{{ $period > 1 ? 's' : '' }}
                                                        </label>
                                                    @endforeach
                                                </div>
                                                <button @click="
                                                    fetch('{{ route('customer.domains.initiate-renewal', $domain->id) }}', {
                                                        method: 'POST',
                                                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Content-Type': 'application/json' },
                                                        body: JSON.stringify({ years: parseInt(years) })
                                                    }).then(r => r.json()).then(data => { if (data.success) window.location.href = data.redirect; else alert(data.message); })
                                                " type="button" class="btn-primary w-full btn-sm">Continue</button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-empty-state
                title="No domains yet"
                description="Search and register a new domain above, or transfer one you already own."
                action-label="Transfer a domain"
                action-href="{{ route('customer.domains.transfer-form') }}"
            />
        @endif
    </x-dashboard-section>
</div>
@endsection
