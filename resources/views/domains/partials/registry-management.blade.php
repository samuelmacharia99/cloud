@php
    $audience = $audience ?? 'customer';
    $nameserverRoute = $nameserverRoute ?? null;
    $registrantRoute = $registrantRoute ?? null;
    $optionsRoute = $optionsRoute ?? null;
    $cloudflareManaged = $cloudflareManaged ?? false;
    $liveLabel = $audience === 'admin' ? 'Live from Cosmotown' : 'Live from registry';
    $registryNoun = $audience === 'admin' ? 'Cosmotown' : 'the registry';
@endphp

@if(! empty($registry['message']))
    <div class="p-4 rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-800 text-sm text-amber-900 dark:text-amber-200">
        {{ $audience === 'admin' ? $registry['message'] : str_ireplace('Cosmotown', 'the registry', $registry['message']) }}
    </div>
@endif

@if($domain->isExpired() && ! $domain->isDnsManaged())
    <div class="p-4 rounded-xl border border-red-200 bg-red-50 dark:bg-red-950/30 dark:border-red-800 text-sm text-red-900 dark:text-red-200">
        @if($audience === 'admin')
            This domain is expired at Cosmotown. There is no restore API — generate and pay a renewal to restore it. If Cosmotown charges a redemption fee the platform must cover, complete that in the Cosmotown dashboard.
        @else
            This domain is expired at the registry. Renew it to restore the registration. The registry does not offer a separate restore button here.
        @endif
    </div>
@endif

@unless($domain->isDnsManaged())
    @if($showEpp ?? true)
    <div x-data="{ revealed: false, copied: false }">
        <div class="flex items-start justify-between gap-3 mb-1">
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">EPP / auth code</h2>
            @if(! empty($registry['epp_live']))
                <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">{{ $liveLabel }}</span>
            @endif
        </div>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
            Use this code to transfer the domain away. Anyone with it can start a transfer — keep it private.
            @if(! empty($registry['locked']))
                Unlock the domain first if {{ $registryNoun }} withholds the code.
            @endif
        </p>
        @if(filled($eppCode))
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                <p class="flex-1 px-3 py-2 font-mono text-sm rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white break-all">
                    <span x-show="!revealed">••••••••••••</span>
                    <span x-show="revealed" x-cloak>{{ $eppCode }}</span>
                </p>
                <div class="flex gap-2 shrink-0">
                    <button type="button" @click="revealed = !revealed" class="px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-800">
                        <span x-text="revealed ? 'Hide' : 'Reveal'"></span>
                    </button>
                    <button type="button"
                        @click="navigator.clipboard.writeText(@js($eppCode)); copied = true; setTimeout(() => copied = false, 2000)"
                        class="px-3 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white">
                        <span x-text="copied ? 'Copied' : 'Copy'"></span>
                    </button>
                </div>
            </div>
        @else
            <p class="text-sm text-slate-500">
                @if(! empty($registry['attempted']))
                    The registry did not return an authorization code. If the domain is locked, unlock it and reload this page.
                @else
                    An EPP / auth code appears here after the domain is registered at the registry.
                @endif
            </p>
        @endif
    </div>
    @endif

    @if($optionsRoute)
        <div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-1">Registry lock &amp; WHOIS privacy</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                Lock prevents transfers. WHOIS privacy hides the registrant from public WHOIS on TLDs that allow it (free at the registry when eligible).
            </p>
            <form method="POST" action="{{ $optionsRoute }}" class="space-y-3">
                @csrf
                @method('PUT')
                <input type="hidden" name="registry_locked" value="0">
                <input type="hidden" name="whois_privacy" value="0">
                <label class="flex items-start gap-3 text-sm text-slate-700 dark:text-slate-300">
                    <input type="checkbox" name="registry_locked" value="1" class="mt-1 rounded border-slate-300" @checked(old('registry_locked', $registry['locked'] ?? $domain->registry_locked))>
                    <span>Transfer lock on</span>
                </label>
                <label class="flex items-start gap-3 text-sm text-slate-700 dark:text-slate-300">
                    <input type="checkbox" name="whois_privacy" value="1" class="mt-1 rounded border-slate-300" @checked(old('whois_privacy', $registry['whois_privacy'] ?? $domain->whois_privacy))>
                    <span>WHOIS privacy on</span>
                </label>
                @if($registry['locked'] ?? $domain->registry_locked)
                    <label class="flex items-start gap-3 text-sm text-amber-800 dark:text-amber-200">
                        <input type="checkbox" name="confirm_unlock" value="1" class="mt-1 rounded border-amber-400">
                        <span>I understand unlocking lets anyone with the EPP code start a transfer.</span>
                    </label>
                    @error('confirm_unlock')
                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                @endif
                <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white text-sm font-medium rounded-lg">Save lock &amp; privacy</button>
            </form>
        </div>
    @endif

    @if($registrantRoute)
        <div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-1">Registrant / WHOIS contact</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                This is the legal registrant at {{ $registryNoun }}, not the platform default contact. ICANN and most registries require accurate details.
            </p>
            <form method="POST" action="{{ $registrantRoute }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @csrf
                @method('PUT')
                @include('domains.partials.registrant-fields', ['contact' => $registrant ?? []])
                <div class="md:col-span-2">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Save registrant</button>
                </div>
            </form>
        </div>
    @endif
@endunless
