@foreach($sharedHostingItems as $item)
    @php $key = $item['key']; @endphp
    <div class="border-t border-slate-200 dark:border-slate-700 pt-6 first:border-t-0 first:pt-0" x-data="sharedHostingDomainConfig('{{ $key }}', {{ Js::from($defaultNameservers) }}, {{ Js::from($domainExtensions->pluck('extension')->values()) }})">
        <h3 class="font-semibold text-slate-900 dark:text-white mb-2">{{ $item['name'] }}</h3>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Choose how you want to connect a domain to this hosting plan.</p>

        <input type="hidden" name="hosting_domain_mode[{{ $key }}]" x-model="mode">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
            <button type="button" @click="mode = 'register'; resetAvailability()"
                :class="mode === 'register' ? 'border-blue-600 bg-blue-50 dark:bg-blue-950/40' : 'border-slate-300 dark:border-slate-600'"
                class="text-left p-4 rounded-lg border-2 transition">
                <p class="font-semibold text-slate-900 dark:text-white">Register new domain</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Domain registration fees apply.</p>
            </button>
            <button type="button" @click="mode = 'existing'"
                :class="mode === 'existing' ? 'border-blue-600 bg-blue-50 dark:bg-blue-950/40' : 'border-slate-300 dark:border-slate-600'"
                class="text-left p-4 rounded-lg border-2 transition">
                <p class="font-semibold text-slate-900 dark:text-white">Use existing domain</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Point nameservers at your registrar.</p>
            </button>
            <button type="button" @click="mode = 'transfer'; resetAvailability()"
                :class="mode === 'transfer' ? 'border-blue-600 bg-blue-50 dark:bg-blue-950/40' : 'border-slate-300 dark:border-slate-600'"
                class="text-left p-4 rounded-lg border-2 transition">
                <p class="font-semibold text-slate-900 dark:text-white">Transfer to us</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Transfer fees apply.</p>
            </button>
        </div>

        <div x-show="mode === 'register'" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain name</label>
                    <input type="text" name="hosting_domain_name[{{ $key }}]" x-model="domain" @input="resetAvailability()" @blur="parseDomainInput()" placeholder="example or example.com" :disabled="mode !== 'register'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                    <p x-show="domainError" class="text-xs text-red-600 dark:text-red-400 mt-1" x-text="domainError"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Extension</label>
                    <select name="hosting_domain_extension[{{ $key }}]" x-model="extension" @change="resetAvailability()" :disabled="mode !== 'register'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                        <option value="">Select extension...</option>
                        @foreach($domainExtensions as $ext)
                            <option value="{{ $ext->extension }}">{{ $ext->extension }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registration period</label>
                    <select name="hosting_domain_years[{{ $key }}]" x-model="years" :disabled="mode !== 'register'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                        @for($y = 1; $y <= 5; $y++)
                            <option value="{{ $y }}">{{ $y }} year{{ $y > 1 ? 's' : '' }}</option>
                        @endfor
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="button" @click="checkAvailability()"
                        :disabled="!domain || !extension || checking"
                        :class="!domain || !extension || checking ? 'opacity-50 cursor-not-allowed bg-slate-400' : 'bg-blue-600 hover:bg-blue-700'"
                        class="w-full px-4 py-2 text-white rounded-lg font-medium transition text-sm">
                        <span x-show="!checking">Check Availability</span>
                        <span x-show="checking" class="inline-flex items-center justify-center gap-2">
                            <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Checking...
                        </span>
                    </button>
                </div>
            </div>

            <div x-show="checked && domain && extension" class="p-4 rounded-lg"
                :class="available ? 'bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700' : 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700'">
                <p class="font-semibold" :class="available ? 'text-emerald-900 dark:text-emerald-100' : 'text-red-900 dark:text-red-100'" x-text="statusMessage"></p>
                <p x-show="available" class="text-sm text-emerald-700 dark:text-emerald-300 mt-1">
                    <span x-text="`Ksh ${price.toLocaleString()} per year`"></span>
                </p>
            </div>

            <p class="text-xs text-amber-700 dark:text-amber-300">Check availability before placing your order. Registration pricing is added to your invoice total.</p>
        </div>

        <div x-show="mode === 'existing'" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Your domain name</label>
                <input type="text" name="hosting_domain_fqdn[{{ $key }}]" placeholder="example.com" :disabled="mode !== 'existing'"
                    class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
            </div>
            <div class="rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 p-4 text-sm text-slate-700 dark:text-slate-300">
                <p class="font-semibold text-blue-900 dark:text-blue-100 mb-2">Point your domain to our nameservers</p>
                <p class="text-xs text-slate-600 dark:text-slate-400 mb-3">At your domain registrar, update the nameservers for your domain to the values below. DNS changes can take up to 24–48 hours to propagate.</p>
                <ul class="space-y-2 font-mono text-xs">
                    @if($defaultNameservers['ns1'])
                        <li class="flex items-center justify-between gap-3 rounded-md bg-white/80 dark:bg-slate-900/60 px-3 py-2">
                            <span><span class="text-slate-500 dark:text-slate-400">NS1:</span> {{ $defaultNameservers['ns1'] }}</span>
                        </li>
                    @endif
                    @if($defaultNameservers['ns2'])
                        <li class="flex items-center justify-between gap-3 rounded-md bg-white/80 dark:bg-slate-900/60 px-3 py-2">
                            <span><span class="text-slate-500 dark:text-slate-400">NS2:</span> {{ $defaultNameservers['ns2'] }}</span>
                        </li>
                    @endif
                    @if($defaultNameservers['ns3'])
                        <li class="flex items-center justify-between gap-3 rounded-md bg-white/80 dark:bg-slate-900/60 px-3 py-2">
                            <span><span class="text-slate-500 dark:text-slate-400">NS3:</span> {{ $defaultNameservers['ns3'] }}</span>
                        </li>
                    @endif
                    @if($defaultNameservers['ns4'])
                        <li class="flex items-center justify-between gap-3 rounded-md bg-white/80 dark:bg-slate-900/60 px-3 py-2">
                            <span><span class="text-slate-500 dark:text-slate-400">NS4:</span> {{ $defaultNameservers['ns4'] }}</span>
                        </li>
                    @endif
                </ul>
            </div>
        </div>

        <div x-show="mode === 'transfer'" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain name</label>
                    <input type="text" name="hosting_domain_name[{{ $key }}]" x-model="domain" @blur="parseDomainInput()" placeholder="example or example.com" :disabled="mode !== 'transfer'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Extension</label>
                    <select name="hosting_domain_extension[{{ $key }}]" x-model="extension" @change="resetAvailability()" :disabled="mode !== 'transfer'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                        @foreach($domainExtensions as $ext)
                            <option value="{{ $ext->extension }}" data-transfer-price="{{ $ext->transfer_price }}">
                                {{ $ext->extension }} (transfer Ksh {{ number_format($ext->transfer_price, 0) }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">EPP / Auth code</label>
                    <input type="text" name="hosting_transfer_epp[{{ $key }}]" :disabled="mode !== 'transfer'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Current registrar</label>
                    <input type="text" name="hosting_transfer_registrar[{{ $key }}]" :disabled="mode !== 'transfer'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registrar website (optional)</label>
                <input type="url" name="hosting_transfer_registrar_url[{{ $key }}]" placeholder="https://" :disabled="mode !== 'transfer'"
                    class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
            </div>
            <p class="text-xs text-amber-700 dark:text-amber-300">Transfer pricing is added to your invoice total.</p>
        </div>

        @error("hosting_domain_mode.{$key}")
            <p class="text-red-600 dark:text-red-400 text-sm mt-2">{{ $message }}</p>
        @enderror
    </div>
@endforeach

<script>
function sharedHostingDomainConfig(cartKey, defaultNs, allowedExtensions) {
    return {
        mode: 'register',
        domain: '',
        extension: '',
        years: '1',
        checking: false,
        checked: false,
        available: false,
        price: 0,
        domainError: '',
        statusMessage: '',
        defaultNs,
        allowedExtensions: allowedExtensions || [],

        init() {
            const field = document.querySelector(`input[name="hosting_domain_mode[${cartKey}]"]`);
            if (field && field.value) {
                this.mode = field.value;
            }
        },

        resetAvailability() {
            this.checked = false;
            this.available = false;
            this.statusMessage = '';
            this.domainError = '';
        },

        parseDomainInput() {
            let raw = (this.domain || '').trim().toLowerCase();
            raw = raw.replace(/^https?:\/\//, '').split('/')[0].replace(/\.+$/, '');

            const extensions = [...this.allowedExtensions]
                .map((ext) => (ext.startsWith('.') ? ext : '.' + ext))
                .sort((a, b) => b.length - a.length);

            if (raw.includes('.')) {
                for (const ext of extensions) {
                    if (!raw.endsWith(ext)) {
                        continue;
                    }

                    const name = raw.slice(0, -ext.length).replace(/\.+$/, '');

                    if (this.isValidLabel(name)) {
                        this.domain = name;
                        this.extension = ext;
                        this.domainError = '';
                        return true;
                    }
                }
            }

            const selectedExt = this.extension
                ? (this.extension.startsWith('.') ? this.extension : '.' + this.extension)
                : '';

            if (selectedExt && extensions.includes(selectedExt) && this.isValidLabel(raw)) {
                this.domain = raw;
                this.domainError = '';
                return true;
            }

            return false;
        },

        async checkAvailability() {
            this.parseDomainInput();

            if (!this.domain || !this.extension) {
                this.domainError = 'Enter a domain name and extension, or type the full domain (e.g. example.com).';
                this.checked = false;
                return;
            }

            this.domainError = '';
            this.checking = true;
            this.checked = false;

            try {
                const response = await fetch('{{ route("customer.cart.check-domain") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        domain: this.domain,
                        extension: this.extension,
                    }),
                });

                const data = await response.json();

                if (data.success) {
                    if (data.domain) {
                        this.domain = data.domain;
                    }
                    if (data.extension) {
                        this.extension = data.extension;
                    }
                    this.available = data.available;
                    this.price = data.price;
                    this.statusMessage = data.message;
                    this.checked = true;
                } else {
                    this.available = false;
                    this.statusMessage = data.message || 'Error checking availability';
                    this.checked = true;
                }
            } catch (error) {
                this.available = false;
                this.statusMessage = 'Error checking domain availability';
                this.checked = true;
                console.error(error);
            } finally {
                this.checking = false;
            }
        },

        isValidLabel(label) {
            if (!label || label.startsWith('-') || label.endsWith('-')) {
                return false;
            }

            return /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i.test(label);
        },
    };
}
</script>
