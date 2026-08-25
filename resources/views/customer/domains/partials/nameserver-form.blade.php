@php
    $nameservers = $nameservers ?? [];
    $cloudflareManaged = $cloudflareManaged ?? false;
    $usesDirectAdmin = $usesDirectAdmin ?? false;
@endphp

<div>
    <div class="flex items-start justify-between gap-3 mb-1">
        <h2 class="text-lg font-bold text-slate-900 dark:text-white">Nameservers</h2>
        @if(! empty($registry['nameservers_live'] ?? false))
            <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">Live from registry</span>
        @endif
    </div>
    <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
        Nameservers are set at the <strong class="font-medium text-slate-700 dark:text-slate-300">registry</strong> (where the domain is registered). They tell the internet which DNS provider hosts your zone — they are not edited on the DNS records page.
        At least two unique nameservers are required.
    </p>

    @if($cloudflareManaged)
        <div class="mb-4 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 p-4 text-sm text-slate-700 dark:text-slate-300 space-y-2">
            <p><strong class="font-medium text-slate-900 dark:text-white">DNS records</strong> — managed on the <a href="{{ route('customer.domains.dns.index', $domain) }}" class="text-blue-600 dark:text-blue-400 hover:underline">DNS page</a> via Cloudflare (A, CNAME, MX, etc.).</p>
            <p><strong class="font-medium text-slate-900 dark:text-white">Nameservers</strong> — managed here at the registry. Point them to Cloudflare to keep using managed DNS, or elsewhere to delegate to another provider.</p>
        </div>
    @elseif($usesDirectAdmin)
        <div class="mb-4 rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30 p-4 text-sm text-blue-900 dark:text-blue-200">
            DNS records for this domain are managed in your hosting control panel. Nameserver changes here update the public registry delegation only.
        </div>
    @endif

    <form method="POST" action="{{ route('customer.domains.nameservers', $domain) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @csrf
        @method('PUT')
        @foreach(['nameserver_1' => 'Nameserver 1', 'nameserver_2' => 'Nameserver 2', 'nameserver_3' => 'Nameserver 3', 'nameserver_4' => 'Nameserver 4'] as $field => $label)
            <div>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">{{ $label }}</label>
                <input type="text" name="{{ $field }}" value="{{ old($field, $nameservers[$field] ?? $domain->{$field}) }}"
                    class="w-full px-3 py-2 font-mono text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600"
                    @if(in_array($field, ['nameserver_1', 'nameserver_2'], true)) required @endif>
                @error($field)<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
        @endforeach
        <div class="md:col-span-2">
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Save nameservers</button>
        </div>
    </form>
</div>
