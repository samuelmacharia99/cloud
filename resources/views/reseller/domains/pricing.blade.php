@extends('layouts.reseller')

@section('title', 'Domain Pricing')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Dashboard</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Domain Pricing</p>
</div>
@endsection

@section('content')
<div class="space-y-6" x-data="domainPricingManager()">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Domain Pricing</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Set registration and renewal prices your customers pay on top of platform wholesale rates.</p>
    </div>

    @if (session('success'))
        <div class="px-4 py-3 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-xl">
            <p class="text-sm text-emerald-800 dark:text-emerald-300">{{ session('success') }}</p>
        </div>
    @endif

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="flex gap-2 border-b border-slate-200 dark:border-slate-800">
            @foreach($periods as $period)
                <button @click="selectedPeriod = {{ $period }}; $nextTick(() => filterExtensions())"
                    :class="{ 'border-b-2 border-blue-600 text-blue-600': selectedPeriod === {{ $period }}, 'text-slate-600 dark:text-slate-400': selectedPeriod !== {{ $period }} }"
                    class="px-4 py-3 font-medium transition">
                    {{ $period }} Year{{ $period > 1 ? 's' : '' }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem]">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800">
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Extension</th>
                        <th class="px-4 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Reg. cost</th>
                        <th class="px-4 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Reg. price</th>
                        <th class="px-4 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Renew cost</th>
                        <th class="px-4 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Renew price</th>
                        <th class="px-4 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Status</th>
                        <th class="px-4 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse($extensions as $extension)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition" x-show="filteredExtensions.some(e => e.id === {{ $extension->id }})">
                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">
                                <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 rounded text-xs font-semibold">{{ $extension->extension }}</span>
                            </td>
                            <td class="px-4 py-4 text-sm text-right text-slate-600 dark:text-slate-400">
                                <span x-text="'KSH ' + formatPrice(getWholesalePrice({{ $extension->id }}))"></span>
                            </td>
                            <td class="px-4 py-4 text-sm text-right text-slate-900 dark:text-white font-medium">
                                <span x-text="'KSH ' + formatPrice(getRetailPrice({{ $extension->id }}))"></span>
                            </td>
                            <td class="px-4 py-4 text-sm text-right text-slate-600 dark:text-slate-400">
                                <span x-text="'KSH ' + formatPrice(getWholesaleRenewalPrice({{ $extension->id }}))"></span>
                            </td>
                            <td class="px-4 py-4 text-sm text-right text-slate-900 dark:text-white font-medium">
                                <span x-text="'KSH ' + formatPrice(getRenewalRetailPrice({{ $extension->id }}))"></span>
                                <span x-show="usesRegistrationRenewalFallback({{ $extension->id }})" class="block text-xs text-slate-500 dark:text-slate-400">same as reg.</span>
                            </td>
                            <td class="px-4 py-4 text-sm">
                                @php $resellerPricing = $extension->resellerPricing()->where('period_years', collect($periods)->first())->first(); @endphp
                                @if($resellerPricing?->enabled)
                                    <span class="px-2 py-1 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 rounded text-xs font-medium">Enabled</span>
                                @else
                                    <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded text-xs font-medium">Disabled</span>
                                @endif
                            </td>
                            <td class="px-4 py-4 text-sm text-right">
                                <button @click="openEditModal({{ $extension->id }})" class="text-blue-600 dark:text-blue-400 hover:text-blue-900 dark:hover:text-blue-300 transition">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">
                                No domain extensions available
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div x-show="showModal" @click="showModal = false" class="fixed inset-0 bg-black/50 z-40"></div>

    <div x-show="showModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div @click.stop class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 w-full max-w-lg max-h-[90vh] overflow-y-auto shadow-xl">
            <div class="p-6 sm:p-8">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Edit Domain Pricing</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-2">
                        <span class="font-semibold text-slate-900 dark:text-white" x-text="selectedExtension"></span>
                        <span class="mx-2 text-slate-400">·</span>
                        <span x-text="selectedPeriod + ' year' + (selectedPeriod > 1 ? 's' : '')"></span>
                    </p>
                </div>

                <form @submit.prevent="savePricing" class="space-y-5">
                    <div class="space-y-4 p-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/30">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Registration</p>

                        <div>
                            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Wholesale price</label>
                            <div class="flex items-center gap-3">
                                <span class="text-sm font-medium text-slate-500 dark:text-slate-400 shrink-0">KSH</span>
                                <input type="text" :value="formatPrice(wholesalePrice)" disabled class="flex-1 min-w-0 px-4 py-2.5 border border-slate-300 dark:border-slate-600 bg-slate-100 dark:bg-slate-800 rounded-lg text-slate-600 dark:text-slate-400 text-sm">
                            </div>
                        </div>

                        <div>
                            <label for="modal_retail_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Your registration price</label>
                            <div class="flex items-center gap-3">
                                <span class="text-sm font-medium text-slate-500 dark:text-slate-400 shrink-0">KSH</span>
                                <input type="number" id="modal_retail_price" x-model.number="retailPrice" @input="calculateModalMargins()" step="0.01" min="0" class="flex-1 min-w-0 px-4 py-2.5 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                            </div>
                        </div>

                        <div class="p-3 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                            <p class="text-xs text-emerald-800 dark:text-emerald-300">
                                Margin: <span class="font-semibold" x-text="'KSH ' + formatPrice(modalMargin)"></span>
                                <span x-text="'(' + modalMarginPercent.toFixed(1) + '%)'"></span>
                            </p>
                        </div>
                    </div>

                    <div class="space-y-4 p-4 rounded-xl border border-slate-200 dark:border-slate-700">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Renewal</p>

                        <div>
                            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Wholesale renewal price</label>
                            <div class="flex items-center gap-3">
                                <span class="text-sm font-medium text-slate-500 dark:text-slate-400 shrink-0">KSH</span>
                                <input type="text" :value="formatPrice(wholesaleRenewalPrice)" disabled class="flex-1 min-w-0 px-4 py-2.5 border border-slate-300 dark:border-slate-600 bg-slate-100 dark:bg-slate-800 rounded-lg text-slate-600 dark:text-slate-400 text-sm">
                            </div>
                        </div>

                        <div>
                            <label for="modal_renewal_retail_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Your renewal price</label>
                            <div class="flex items-center gap-3">
                                <span class="text-sm font-medium text-slate-500 dark:text-slate-400 shrink-0">KSH</span>
                                <input type="number" id="modal_renewal_retail_price" x-model="renewalRetailPrice" @input="calculateModalMargins()" step="0.01" min="0" placeholder="Same as registration" class="flex-1 min-w-0 px-4 py-2.5 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                            </div>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Leave blank to charge the same as your registration price.</p>
                        </div>

                        <div class="p-3 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                            <p class="text-xs text-emerald-800 dark:text-emerald-300">
                                Effective renewal: <span class="font-semibold" x-text="'KSH ' + formatPrice(effectiveRenewalRetailPrice())"></span>
                                · Margin: <span class="font-semibold" x-text="'KSH ' + formatPrice(modalRenewalMargin)"></span>
                                <span x-text="'(' + modalRenewalMarginPercent.toFixed(1) + '%)'"></span>
                            </p>
                        </div>
                    </div>

                    <div>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" x-model="enabled" class="w-4 h-4 text-blue-600 rounded">
                            <span class="text-sm text-slate-700 dark:text-slate-300">Offer this extension to customers</span>
                        </label>
                    </div>

                    <div class="flex gap-3 pt-2 border-t border-slate-200 dark:border-slate-800">
                        <button type="button" @click="showModal = false" class="flex-1 px-6 py-2.5 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                            Cancel
                        </button>
                        <button type="submit" class="flex-1 px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function domainPricingManager() {
    return {
        showModal: false,
        selectedPeriod: @json($periods[0]),
        selectedExtensionId: null,
        retailPrice: null,
        renewalRetailPrice: '',
        wholesalePrice: null,
        wholesaleRenewalPrice: null,
        enabled: true,
        modalMargin: 0,
        modalMarginPercent: 0,
        modalRenewalMargin: 0,
        modalRenewalMarginPercent: 0,
        filteredExtensions: [],
        extensions: @json($extensionsData),
        periods: @json($periods),
        formatExtension(value) {
            if (!value) {
                return '';
            }

            return value.startsWith('.') ? value : '.' + value;
        },
        get selectedExtension() {
            const ext = this.extensions.find(e => e.id === this.selectedExtensionId);
            return ext ? this.formatExtension(ext.extension) : '';
        },
        init() {
            this.filterExtensions();
        },
        filterExtensions() {
            this.filteredExtensions = this.extensions;
        },
        openEditModal(extensionId) {
            this.selectedExtensionId = extensionId;
            const extension = this.extensions.find(e => e.id === extensionId);

            if (extension) {
                const wholesalePricing = extension.pricing.find(p => p.period_years == this.selectedPeriod);
                const resellerPricing = extension.resellerPricing.find(p => p.period_years == this.selectedPeriod);

                this.wholesalePrice = wholesalePricing?.price || 0;
                this.wholesaleRenewalPrice = wholesalePricing?.renewal_price ?? wholesalePricing?.price ?? 0;
                this.retailPrice = resellerPricing?.retail_price || 0;
                this.renewalRetailPrice = resellerPricing?.renewal_retail_price ?? '';
                this.enabled = resellerPricing?.enabled ?? true;
                this.calculateModalMargins();
            }

            this.showModal = true;
        },
        effectiveRenewalRetailPrice() {
            const renewal = parseFloat(this.renewalRetailPrice);
            if (!Number.isNaN(renewal) && this.renewalRetailPrice !== '' && this.renewalRetailPrice !== null) {
                return renewal;
            }

            return parseFloat(this.retailPrice) || 0;
        },
        calculateModalMargins() {
            const wholesale = parseFloat(this.wholesalePrice) || 0;
            const retail = parseFloat(this.retailPrice) || 0;
            const wholesaleRenewal = parseFloat(this.wholesaleRenewalPrice) || 0;
            const renewalRetail = this.effectiveRenewalRetailPrice();

            this.modalMargin = retail - wholesale;
            this.modalMarginPercent = wholesale > 0 ? (this.modalMargin / wholesale) * 100 : 0;
            this.modalRenewalMargin = renewalRetail - wholesaleRenewal;
            this.modalRenewalMarginPercent = wholesaleRenewal > 0 ? (this.modalRenewalMargin / wholesaleRenewal) * 100 : 0;
        },
        getWholesalePrice(extensionId) {
            const ext = this.extensions.find(e => e.id === extensionId);
            if (!ext) return 0;
            const pricing = ext.pricing.find(p => p.period_years == this.selectedPeriod);
            return pricing?.price || 0;
        },
        getWholesaleRenewalPrice(extensionId) {
            const ext = this.extensions.find(e => e.id === extensionId);
            if (!ext) return 0;
            const pricing = ext.pricing.find(p => p.period_years == this.selectedPeriod);
            return pricing?.renewal_price ?? pricing?.price ?? 0;
        },
        getRetailPrice(extensionId) {
            const ext = this.extensions.find(e => e.id === extensionId);
            if (!ext) return 0;
            const pricing = ext.resellerPricing.find(p => p.period_years == this.selectedPeriod);
            return pricing?.retail_price || 0;
        },
        getRenewalRetailPrice(extensionId) {
            const ext = this.extensions.find(e => e.id === extensionId);
            if (!ext) return 0;
            const pricing = ext.resellerPricing.find(p => p.period_years == this.selectedPeriod);
            if (!pricing) return 0;

            return pricing.renewal_retail_price ?? pricing.retail_price ?? 0;
        },
        usesRegistrationRenewalFallback(extensionId) {
            const ext = this.extensions.find(e => e.id === extensionId);
            if (!ext) return false;
            const pricing = ext.resellerPricing.find(p => p.period_years == this.selectedPeriod);

            return pricing && (pricing.renewal_retail_price === null || pricing.renewal_retail_price === undefined);
        },
        formatPrice(price) {
            return parseFloat(price || 0).toFixed(2);
        },
        savePricing() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '{{ route("reseller.domains.pricing.update") }}';

            const fields = {
                '_token': '{{ csrf_token() }}',
                'domain_extension_id': this.selectedExtensionId,
                'period_years': this.selectedPeriod,
                'retail_price': this.retailPrice,
                'renewal_retail_price': this.renewalRetailPrice === '' ? '' : this.renewalRetailPrice,
                'enabled': this.enabled ? '1' : '0',
            };

            for (const [key, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
        }
    }
}
</script>
@endsection
