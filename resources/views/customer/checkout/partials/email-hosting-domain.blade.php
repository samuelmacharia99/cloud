@foreach($emailHostingItems as $item)
    @php
        $key = $item['key'];
        $cloudflareManagedFqdns = ($customerDomains ?? collect())
            ->filter(fn ($domain) => $domain->cloudflare_dns_enabled && filled($domain->cloudflare_zone_id))
            ->map(fn ($domain) => strtolower($domain->fqdn()))
            ->values()
            ->all();
    @endphp
    <div
        class="mb-6 last:mb-0 border-t border-slate-200 dark:border-slate-700 pt-6 first:border-t-0 first:pt-0"
        x-data="{
            domain: @js(old("email_domain.$key", '')),
            cfDomains: @js($cloudflareManagedFqdns),
            get normalized() {
                return (this.domain || '').trim().toLowerCase().replace(/\.$/, '');
            },
            get isCloudflareManaged() {
                return this.normalized !== '' && this.cfDomains.includes(this.normalized);
            }
        }"
    >
        <p class="font-medium text-slate-900 dark:text-white mb-3">{{ $item['name'] }}</p>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Mail domain</label>
        <input type="text" name="email_domain[{{ $key }}]" x-model="domain"
            list="email-domains-{{ $key }}"
            placeholder="example.com"
            required
            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
        @if(($customerDomains ?? collect())->isNotEmpty())
            <datalist id="email-domains-{{ $key }}">
                @foreach($customerDomains as $domain)
                    <option value="{{ $domain->fqdn() }}"></option>
                @endforeach
            </datalist>
        @endif
        <p
            x-show="isCloudflareManaged"
            x-cloak
            class="mt-2 text-sm text-teal-700 dark:text-teal-300 bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-800 rounded-lg px-3 py-2"
        >
            This domain’s DNS is managed on Talksasa. After payment we will create MX, SPF, DKIM, and DMARC records automatically.
        </p>
        <p
            x-show="normalized && !isCloudflareManaged"
            x-cloak
            class="mt-2 text-sm text-slate-500 dark:text-slate-400"
        >
            If DNS is managed elsewhere, copy the recommended MX/SPF/DKIM/DMARC records from your email console after provisioning.
        </p>
        @error("email_domain.$key")
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>
@endforeach
