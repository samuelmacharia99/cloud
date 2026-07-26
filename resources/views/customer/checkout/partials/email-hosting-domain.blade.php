@foreach($emailHostingItems as $item)
    @php
        $key = $item['key'];
        $linkedDomain = $linkedEmailDomains[$key] ?? null;
        $ownedDomains = ($customerDomains ?? collect())->values();
        $cloudflareManagedFqdns = $ownedDomains
            ->filter(fn ($domain) => $domain->cloudflare_dns_enabled && filled($domain->cloudflare_zone_id))
            ->map(fn ($domain) => strtolower($domain->fqdn()))
            ->values()
            ->all();
    @endphp
    <div class="border-t border-slate-200 dark:border-slate-700 pt-6 first:border-t-0 first:pt-0"
        x-data="emailHostingDomainConfig(
            '{{ $key }}',
            {{ Js::from($defaultNameservers) }},
            {{ Js::from($domainExtensions->pluck('extension')->values()) }},
            {{ Js::from($linkedDomain) }},
            {{ Js::from($ownedDomains->map(fn ($d) => ['fqdn' => $d->fqdn(), 'cloudflare' => (bool) ($d->cloudflare_dns_enabled && $d->cloudflare_zone_id)])->values()) }},
            {{ Js::from($cloudflareManagedFqdns) }}
        )"
        @checkout-domain-removed.window="if ($event.detail.cartKey === cartKey) { setAddedToCart(false); }">
        <h3 class="font-semibold text-slate-900 dark:text-white mb-2">{{ $item['name'] }}</h3>

        @if($linkedDomain)
            <input type="hidden" name="email_domain_mode[{{ $key }}]" value="from_cart">
            <div class="rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 p-4 mb-4">
                <p class="font-medium text-emerald-900 dark:text-emerald-100">Using domain from your cart</p>
                <p class="text-sm text-emerald-800 dark:text-emerald-200 mt-1 font-mono">{{ $linkedDomain['fqdn'] }} ({{ $linkedDomain['years'] }} year{{ $linkedDomain['years'] > 1 ? 's' : '' }})</p>
                <p class="text-xs text-emerald-700 dark:text-emerald-300 mt-2">After payment we will provision Mailcow and apply MX/SPF/DKIM/DMARC on Talksasa DNS automatically.</p>
            </div>
        @elseif(! empty($item['mail_domain']))
            <input type="hidden" name="email_domain_mode[{{ $key }}]" value="existing">
            <input type="hidden" name="email_domain_fqdn[{{ $key }}]" value="{{ $item['mail_domain'] }}">
            <div class="rounded-lg bg-teal-50 dark:bg-teal-950/30 border border-teal-200 dark:border-teal-800 p-4 mb-4">
                <p class="font-medium text-teal-900 dark:text-teal-100">Mail domain from your website</p>
                <p class="text-sm text-teal-800 dark:text-teal-200 mt-1 font-mono">{{ $item['mail_domain'] }}</p>
                <p class="text-xs text-teal-700 dark:text-teal-300 mt-2">Provided via the public API cart. Point MX/SPF/DKIM/DMARC to Talksasa after checkout (or use nameservers we show in the email console).</p>
            </div>
        @else
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Choose the domain that will receive mail on this plan.</p>

            <input type="hidden" name="email_domain_mode[{{ $key }}]" x-model="mode">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
                <button type="button" @click="mode = 'register'; clearDomainCart()"
                    :class="mode === 'register' ? 'border-teal-600 bg-teal-50 dark:bg-teal-950/40' : 'border-slate-300 dark:border-slate-600'"
                    class="text-left p-4 rounded-lg border-2 transition">
                    <p class="font-semibold text-slate-900 dark:text-white">Register new domain</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Registration fees apply. DNS auto-configured.</p>
                </button>
                <button type="button" @click="mode = 'existing'; clearDomainCart()"
                    :class="mode === 'existing' ? 'border-teal-600 bg-teal-50 dark:bg-teal-950/40' : 'border-slate-300 dark:border-slate-600'"
                    class="text-left p-4 rounded-lg border-2 transition">
                    <p class="font-semibold text-slate-900 dark:text-white">Use existing domain</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Owned here or at another registrar.</p>
                </button>
                <button type="button" @click="mode = 'transfer'; clearDomainCart()"
                    :class="mode === 'transfer' ? 'border-teal-600 bg-teal-50 dark:bg-teal-950/40' : 'border-slate-300 dark:border-slate-600'"
                    class="text-left p-4 rounded-lg border-2 transition">
                    <p class="font-semibold text-slate-900 dark:text-white">Transfer to us</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Transfer fees apply.</p>
                </button>
            </div>

            <div x-show="mode === 'register'" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain name</label>
                        <input type="text" name="email_domain_name[{{ $key }}]" x-model="domain" @input="resetAvailability()" @blur="parseDomainInput()" placeholder="example or example.com" :disabled="mode !== 'register'"
                            class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                        <p x-show="domainError" class="text-xs text-red-600 dark:text-red-400 mt-1" x-text="domainError"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Extension</label>
                        <select name="email_domain_extension[{{ $key }}]" x-model="extension" @change="resetAvailability()" :disabled="mode !== 'register'"
                            class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                            <option value="">Select extension...</option>
                            @foreach($domainExtensions as $ext)
                                <option value="{{ $ext->extension }}">{{ $ext->extension }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registration period</label>
                        <select name="email_domain_years[{{ $key }}]" x-model="years" @change="onYearsChange()" :disabled="mode !== 'register'"
                            class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                            @for($y = 1; $y <= 5; $y++)
                                <option value="{{ $y }}">{{ $y }} year{{ $y > 1 ? 's' : '' }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="button" @click="checkAvailability()"
                            :disabled="!domain || !extension || checking"
                            :class="!domain || !extension || checking ? 'opacity-50 cursor-not-allowed bg-slate-400' : 'bg-teal-600 hover:bg-teal-700'"
                            class="w-full px-4 py-2 text-white rounded-lg font-medium transition text-sm">
                            <span x-show="!checking">Check Availability</span>
                            <span x-show="checking">Checking...</span>
                        </button>
                    </div>
                </div>

                <div x-show="checked && domain && extension" class="p-4 rounded-lg"
                    :class="available ? 'bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700' : 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700'">
                    <p class="font-semibold" :class="available ? 'text-emerald-900 dark:text-emerald-100' : 'text-red-900 dark:text-red-100'" x-text="statusMessage"></p>
                    <p x-show="available" class="text-sm text-emerald-700 dark:text-emerald-300 mt-1">
                        <span x-text="`Ksh ${price.toLocaleString()} for ${years} year${years > 1 ? 's' : ''}`"></span>
                    </p>
                </div>

                <div x-show="available && checked && !addedToCart" class="flex justify-end">
                    <button type="button" @click="addToCart()"
                        class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition text-sm">
                        Add to order
                    </button>
                </div>

                <div x-show="addedToCart" class="p-4 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <p class="font-medium text-emerald-900 dark:text-emerald-100">
                            <span x-text="`${domain}${extension}`"></span> added to your order
                        </p>
                        <button type="button" @click="removeFromCart()"
                            class="text-sm font-medium text-red-600 dark:text-red-400 hover:underline whitespace-nowrap">
                            Remove
                        </button>
                    </div>
                </div>

                <input type="hidden" name="email_domain_added[{{ $key }}]" :value="addedToCart ? '1' : '0'">
                <p class="text-xs text-amber-700 dark:text-amber-300">After payment we create the Mailcow domain and apply MX/SPF/DKIM/DMARC on Talksasa Cloudflare DNS.</p>
            </div>

            <div x-show="mode === 'existing'" class="space-y-4">
                @if($ownedDomains->isNotEmpty())
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Your domains</label>
                        <select x-model="ownedFqdn" @change="applyOwnedDomain()" :disabled="mode !== 'existing'"
                            class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                            <option value="">Select a domain you manage here…</option>
                            @foreach($ownedDomains as $domain)
                                <option value="{{ $domain->fqdn() }}">
                                    {{ $domain->fqdn() }}
                                    @if($domain->cloudflare_dns_enabled && $domain->cloudflare_zone_id)
                                        (Talksasa DNS — auto MX)
                                    @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Or type any domain below.</p>
                @endif
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Mail domain</label>
                    <input type="text" name="email_domain_fqdn[{{ $key }}]" x-model="existingFqdn" placeholder="example.com" :disabled="mode !== 'existing'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                </div>
                <p
                    x-show="isCloudflareManaged"
                    x-cloak
                    class="text-sm text-teal-700 dark:text-teal-300 bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-800 rounded-lg px-3 py-2"
                >
                    This domain uses Talksasa Cloudflare DNS. MX, SPF, DKIM, and DMARC will be applied automatically after payment.
                </p>
                <div x-show="existingFqdn && !isCloudflareManaged" x-cloak class="rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 p-4 text-sm text-slate-700 dark:text-slate-300">
                    <p class="font-semibold text-blue-900 dark:text-blue-100 mb-2">DNS for mail</p>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mb-3">Point nameservers to Talksasa for automatic mail records, or copy MX/SPF/DKIM/DMARC from your email console after provisioning.</p>
                    <ul class="space-y-2 font-mono text-xs">
                        @foreach(['ns1','ns2','ns3','ns4'] as $nsKey)
                            @if(!empty($defaultNameservers[$nsKey]))
                                <li class="rounded-md bg-white/80 dark:bg-slate-900/60 px-3 py-2">
                                    <span class="text-slate-500 dark:text-slate-400">{{ strtoupper($nsKey) }}:</span> {{ $defaultNameservers[$nsKey] }}
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            </div>

            <div x-show="mode === 'transfer'" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain name</label>
                        <input type="text" name="email_domain_name[{{ $key }}]" x-model="domain" @blur="parseDomainInput()" placeholder="example or example.com" :disabled="mode !== 'transfer'"
                            class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Extension</label>
                        <select name="email_domain_extension[{{ $key }}]" x-model="extension" :disabled="mode !== 'transfer'"
                            class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                            @foreach($domainExtensions as $ext)
                                <option value="{{ $ext->extension }}">
                                    {{ $ext->extension }} (transfer Ksh {{ number_format($ext->transfer_price, 0) }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">EPP / Auth code</label>
                        <input type="text" name="email_transfer_epp[{{ $key }}]" :disabled="mode !== 'transfer'"
                            class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Current registrar</label>
                        <input type="text" name="email_transfer_registrar[{{ $key }}]" :disabled="mode !== 'transfer'"
                            class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registrar website (optional)</label>
                    <input type="url" name="email_transfer_registrar_url[{{ $key }}]" placeholder="https://" :disabled="mode !== 'transfer'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                </div>
                <p class="text-xs text-amber-700 dark:text-amber-300">Transfer pricing is added to your invoice. Mail DNS is applied once the domain is active on Talksasa.</p>
            </div>
        @endif

        @error("email_domain_mode.{$key}")
            <p class="text-red-600 dark:text-red-400 text-sm mt-2">{{ $message }}</p>
        @enderror
        @error("email_domain_added.{$key}")
            <p class="text-red-600 dark:text-red-400 text-sm mt-2">{{ $message }}</p>
        @enderror
        @error("email_domain_fqdn.{$key}")
            <p class="text-red-600 dark:text-red-400 text-sm mt-2">{{ $message }}</p>
        @enderror
    </div>
@endforeach

<script>
function emailHostingDomainConfig(cartKey, defaultNs, allowedExtensions, linkedDomain, ownedDomains, cfDomains) {
    return {
        cartKey,
        mode: linkedDomain ? 'from_cart' : (ownedDomains && ownedDomains.length ? 'existing' : 'register'),
        linkedDomain: linkedDomain || null,
        domain: '',
        extension: '',
        years: '1',
        checking: false,
        checked: false,
        available: false,
        addedToCart: false,
        price: 0,
        domainError: '',
        statusMessage: '',
        defaultNs,
        allowedExtensions: allowedExtensions || [],
        ownedDomains: ownedDomains || [],
        cfDomains: cfDomains || [],
        ownedFqdn: '',
        existingFqdn: '',

        get isCloudflareManaged() {
            const n = (this.existingFqdn || '').trim().toLowerCase().replace(/\.$/, '');
            return n !== '' && this.cfDomains.includes(n);
        },

        applyOwnedDomain() {
            this.existingFqdn = this.ownedFqdn || '';
        },

        clearDomainCart() {
            this.resetAvailability();
        },

        resetAvailability() {
            if (this.addedToCart) {
                this.removeFromCart();
            }
            this.checked = false;
            this.available = false;
            this.statusMessage = '';
            this.domainError = '';
        },

        onYearsChange() {
            this.resetAvailability();
        },

        setAddedToCart(value) {
            this.addedToCart = value;
            if (value) {
                const ext = (this.extension || '').startsWith('.') ? this.extension : '.' + (this.extension || '');
                const years = parseInt(this.years, 10) || 1;
                window.dispatchEvent(new CustomEvent('checkout-domain-added', {
                    detail: {
                        cartKey: this.cartKey,
                        label: `${this.domain}${ext}`,
                        description: `Domain registration (${years} year${years > 1 ? 's' : ''})`,
                        amount: this.price,
                    },
                }));
            } else {
                window.dispatchEvent(new CustomEvent('checkout-domain-removed', {
                    detail: { cartKey: this.cartKey },
                }));
            }
        },

        parseDomainInput() {
            const raw = (this.domain || '').trim().toLowerCase();
            if (!raw.includes('.')) {
                return;
            }
            const parts = raw.split('.');
            const ext = '.' + parts.slice(1).join('.');
            if (this.allowedExtensions.includes(ext)) {
                this.domain = parts[0];
                this.extension = ext;
                this.resetAvailability();
            }
        },

        async checkAvailability() {
            if (!this.domain || !this.extension) {
                return;
            }
            this.checking = true;
            this.checked = false;
            try {
                const response = await fetch(@js(route('customer.cart.check-domain')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        domain: this.domain,
                        extension: this.extension,
                        years: parseInt(this.years, 10) || 1,
                    }),
                });
                const data = await response.json();
                this.checked = true;
                this.available = !!data.available;
                this.price = Number(data.price || 0);
                this.statusMessage = data.message || (this.available ? 'Domain is available' : 'Domain is not available');
            } catch (e) {
                this.checked = true;
                this.available = false;
                this.statusMessage = 'Could not check availability. Try again.';
            } finally {
                this.checking = false;
            }
        },

        addToCart() {
            if (!this.available) {
                return;
            }
            this.setAddedToCart(true);
        },

        removeFromCart() {
            this.setAddedToCart(false);
            this.$dispatch('checkout-domain-removed', { cartKey: this.cartKey });
        },
    };
}
</script>
