@php
    $stored = $stored ?? [];
    $defaults = $defaults ?? [];
    $platformDefaults = $platformDefaults ?? [];
    $usingDefault = ($stored['use_default'] ?? true) !== false;
    $customNs = array_values(array_filter([
        $stored['ns1'] ?? null,
        $stored['ns2'] ?? null,
        $stored['ns3'] ?? null,
        $stored['ns4'] ?? null,
    ], fn ($value) => ! $usingDefault && filled($value)));
@endphp

<div
    x-data="resellerNameserverConfig(@js([
        'cartKey' => $cartKey,
        'stored' => $stored,
        'defaults' => $defaults,
        'platformDefaults' => $platformDefaults,
        'inputPrefix' => $inputPrefix ?? null,
        'saveUrl' => $saveUrl ?? null,
    ]))"
    class="border border-slate-200 dark:border-slate-700 rounded-lg p-4 bg-white dark:bg-slate-900"
>
    <div class="flex items-center gap-2 mb-3">
        <svg class="w-4 h-4 text-slate-500 dark:text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 10-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
        </svg>
        <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Name Servers</h4>
    </div>

    <template x-if="inputPrefix">
        <div class="hidden">
            <input type="hidden" :name="fieldName('use_default')" :value="useDefault ? 1 : 0">
            <input type="hidden" :name="fieldName('ns1')" :value="resolvedNs().ns1">
            <input type="hidden" :name="fieldName('ns2')" :value="resolvedNs().ns2 || ''">
            <input type="hidden" :name="fieldName('ns3')" :value="resolvedNs().ns3 || ''">
            <input type="hidden" :name="fieldName('ns4')" :value="resolvedNs().ns4 || ''">
        </div>
    </template>

    <div class="space-y-2">
        <label class="flex items-start gap-3 cursor-pointer p-2 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition">
            <input type="radio" :name="inputPrefix ? fieldName('mode') : 'ns_mode_' + cartKey" @change="useDefault = true" :checked="useDefault" class="mt-0.5 text-purple-600 focus:ring-purple-500">
            <div>
                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                    Use default nameservers
                    <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">Recommended</span>
                </p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 font-mono">
                    <span x-text="defaults.ns1"></span><template x-if="defaults.ns2"><span class="ml-2" x-text="defaults.ns2"></span></template>
                </p>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Platform fallback: <span class="font-mono" x-text="platformDefaults.ns1"></span><template x-if="platformDefaults.ns2"><span class="ml-1 font-mono" x-text="platformDefaults.ns2"></span></template></p>
            </div>
        </label>

        <label class="flex items-start gap-3 cursor-pointer p-2 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition">
            <input type="radio" :name="inputPrefix ? fieldName('mode') : 'ns_mode_' + cartKey" @change="useDefault = false" :checked="!useDefault" class="mt-0.5 text-purple-600 focus:ring-purple-500">
            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Use custom nameservers for this domain</p>
        </label>
    </div>

    <div x-show="!useDefault" x-transition class="mt-4 space-y-3">
        <div class="flex gap-2">
            <input
                type="text"
                x-model="nsInput"
                @keydown.enter.prevent="addNs()"
                placeholder="e.g. ns1.yourdomain.com"
                :disabled="customNs.length >= 4"
                class="flex-1 px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-400 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 disabled:opacity-50"
            >
            <button type="button" @click="addNs()" :disabled="!nsInput.trim() || customNs.length >= 4" class="px-4 py-2 text-sm bg-purple-600 hover:bg-purple-700 disabled:opacity-40 text-white rounded-lg font-medium transition">
                + Add
            </button>
        </div>
        <p x-show="nsInputError" class="text-xs text-red-600 dark:text-red-400" x-text="nsInputError"></p>
        <p class="text-xs" :class="customNs.length === 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400 dark:text-slate-500'">
            <span x-show="customNs.length === 0">At least one nameserver is required.</span>
            <span x-show="customNs.length > 0 && customNs.length < 4" x-text="`${customNs.length}/4 nameservers added`"></span>
            <span x-show="customNs.length === 4">Maximum 4 nameservers reached</span>
        </p>
        <div x-show="customNs.length > 0" class="flex flex-wrap gap-2">
            <template x-for="(ns, idx) in customNs" :key="idx">
                <div class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-full text-xs font-mono text-slate-700 dark:text-slate-300">
                    <span x-text="ns"></span>
                    <button type="button" @click="removeNs(idx)" class="rounded-full hover:bg-slate-200 dark:hover:bg-slate-600 p-0.5 transition">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </template>
        </div>
    </div>

    <div x-show="saveUrl" class="flex items-center justify-between mt-4 pt-3 border-t border-slate-100 dark:border-slate-700">
        <div>
            <p x-show="error" class="text-xs text-red-600 dark:text-red-400" x-text="error"></p>
            <p x-show="saved && !error" class="text-xs text-emerald-600 dark:text-emerald-400">Nameservers saved</p>
        </div>
        <button type="button" @click="save()" :disabled="saving || (!useDefault && customNs.length === 0)" class="px-4 py-2 text-sm bg-purple-600 hover:bg-purple-700 disabled:opacity-40 text-white rounded-lg font-medium transition">
            <span x-show="!saving">Save nameservers</span>
            <span x-show="saving">Saving...</span>
        </button>
    </div>
</div>

@once
<script>
function resellerNameserverConfig(config) {
    const usingDefault = config.stored.use_default !== false;

    return {
        cartKey: config.cartKey,
        defaults: config.defaults,
        platformDefaults: config.platformDefaults,
        inputPrefix: config.inputPrefix,
        saveUrl: config.saveUrl,
        useDefault: usingDefault,
        nsInput: '',
        nsInputError: null,
        customNs: [],
        saving: false,
        saved: false,
        error: null,

        init() {
            if (!usingDefault) {
                [config.stored.ns1, config.stored.ns2, config.stored.ns3, config.stored.ns4]
                    .filter(Boolean)
                    .forEach(ns => this.customNs.push(ns));
            }
        },

        fieldName(key) {
            return this.inputPrefix ? `${this.inputPrefix}[${key}]` : key;
        },

        resolvedNs() {
            if (this.useDefault) {
                return this.defaults;
            }

            return {
                ns1: this.customNs[0] || '',
                ns2: this.customNs[1] || null,
                ns3: this.customNs[2] || null,
                ns4: this.customNs[3] || null,
            };
        },

        addNs() {
            const val = this.nsInput.trim().toLowerCase();
            if (!val) return;
            if (this.customNs.length >= 4) { this.nsInputError = 'Maximum 4 nameservers'; return; }
            if (this.customNs.includes(val)) { this.nsInputError = 'Already added'; return; }
            if (!/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/.test(val)) {
                this.nsInputError = 'Invalid hostname format';
                return;
            }
            this.customNs.push(val);
            this.nsInput = '';
            this.nsInputError = null;
        },

        removeNs(idx) {
            this.customNs.splice(idx, 1);
        },

        async save() {
            if (!this.saveUrl) return;

            this.error = null;
            this.saved = false;

            if (!this.useDefault && this.customNs.length === 0) {
                this.error = 'Please add at least one nameserver';
                return;
            }

            const payload = {
                use_default: this.useDefault,
                ...this.resolvedNs(),
            };

            this.saving = true;
            try {
                const res = await fetch(this.saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Failed to save nameservers');
                this.saved = true;
                setTimeout(() => { this.saved = false; }, 4000);
            } catch (err) {
                this.error = err.message;
            } finally {
                this.saving = false;
            }
        },
    };
}
</script>
@endonce
