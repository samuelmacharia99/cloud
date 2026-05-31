@foreach($sharedHostingItems as $item)
    @php $key = $item['key']; @endphp
    <div class="border-t border-slate-200 dark:border-slate-700 pt-6 first:border-t-0 first:pt-0" x-data="sharedHostingDomainConfig('{{ $key }}')">
        <h3 class="font-semibold text-slate-900 dark:text-white mb-2">{{ $item['name'] }}</h3>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Choose how you want to connect a domain to this hosting plan.</p>

        <input type="hidden" name="hosting_domain_mode[{{ $key }}]" x-model="mode">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
            <button type="button" @click="mode = 'register'"
                :class="mode === 'register' ? 'border-blue-600 bg-blue-50 dark:bg-blue-950/40' : 'border-slate-300 dark:border-slate-600'"
                class="text-left p-4 rounded-lg border-2 transition">
                <p class="font-semibold text-slate-900 dark:text-white">Register new domain</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Domain registration fees apply.</p>
            </button>
            <button type="button" @click="mode = 'existing'"
                :class="mode === 'existing' ? 'border-blue-600 bg-blue-50 dark:bg-blue-950/40' : 'border-slate-300 dark:border-slate-600'"
                class="text-left p-4 rounded-lg border-2 transition">
                <p class="font-semibold text-slate-900 dark:text-white">Use existing domain</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Update nameservers at your registrar.</p>
            </button>
            <button type="button" @click="mode = 'transfer'"
                :class="mode === 'transfer' ? 'border-blue-600 bg-blue-50 dark:bg-blue-950/40' : 'border-slate-300 dark:border-slate-600'"
                class="text-left p-4 rounded-lg border-2 transition">
                <p class="font-semibold text-slate-900 dark:text-white">Transfer to us</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Transfer fees apply.</p>
            </button>
        </div>

        <div x-show="mode === 'register'" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain name</label>
                    <input type="text" name="hosting_domain_name[{{ $key }}]" placeholder="example" :disabled="mode !== 'register'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Extension</label>
                    <select name="hosting_domain_extension[{{ $key }}]" :disabled="mode !== 'register'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                        @foreach($domainExtensions as $ext)
                            <option value="{{ $ext->extension }}">{{ $ext->extension }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registration period</label>
                    <select name="hosting_domain_years[{{ $key }}]" :disabled="mode !== 'register'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                        @for($y = 1; $y <= 5; $y++)
                            <option value="{{ $y }}">{{ $y }} year{{ $y > 1 ? 's' : '' }}</option>
                        @endfor
                    </select>
                </div>
            </div>
            <p class="text-xs text-amber-700 dark:text-amber-300">Registration pricing is added to your invoice total.</p>
        </div>

        <div x-show="mode === 'existing'" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Your domain name</label>
                <input type="text" name="hosting_domain_fqdn[{{ $key }}]" placeholder="example.com" :disabled="mode !== 'existing'"
                    class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
            </div>
            <div class="rounded-lg bg-slate-50 dark:bg-slate-800 p-4 text-sm text-slate-700 dark:text-slate-300">
                <p class="font-semibold mb-2">After payment, update your domain nameservers to:</p>
                <ul class="space-y-1 font-mono text-xs">
                    @if($defaultNameservers['ns1'])<li>NS1: {{ $defaultNameservers['ns1'] }}</li>@endif
                    @if($defaultNameservers['ns2'])<li>NS2: {{ $defaultNameservers['ns2'] }}</li>@endif
                    @if($defaultNameservers['ns3'])<li>NS3: {{ $defaultNameservers['ns3'] }}</li>@endif
                    @if($defaultNameservers['ns4'])<li>NS4: {{ $defaultNameservers['ns4'] }}</li>@endif
                </ul>
            </div>
        </div>

        <div x-show="mode === 'transfer'" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain name</label>
                    <input type="text" name="hosting_domain_name[{{ $key }}]" placeholder="example" :disabled="mode !== 'transfer'"
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white disabled:opacity-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Extension</label>
                    <select name="hosting_domain_extension[{{ $key }}]" :disabled="mode !== 'transfer'"
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
function sharedHostingDomainConfig(cartKey) {
    return {
        mode: 'register',
        init() {
            const field = document.querySelector(`input[name="hosting_domain_mode[${cartKey}]"]`);
            if (field && field.value) {
                this.mode = field.value;
            }
        },
    };
}
</script>
