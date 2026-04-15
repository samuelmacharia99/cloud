@extends('layouts.reseller')

@section('title', 'Domain Pricing')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('reseller.dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Dashboard</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Domain Pricing</p>
</div>
@endsection

@section('content')
<div class="space-y-6" x-data="domainPricingManager()">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Domain Pricing</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Set your domain pricing on top of admin wholesale rates.</p>
    </div>

    <!-- Period Tabs -->
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

    <!-- Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800">
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Extension</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Your Cost</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Your Price</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Margin</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Status</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse($extensions as $extension)
                        @php
                            $wholesalePricing = $extension->pricing()->where('period_years', collect($periods)->first())->first();
                            $resellerPricing = $extension->resellerPricing()->where('period_years', collect($periods)->first())->first();
                        @endphp
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition" x-show="filteredExtensions.some(e => e.id === {{ $extension->id }})">
                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">
                                <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 rounded text-xs font-semibold">.{{ $extension->extension }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm text-right text-slate-600 dark:text-slate-400">
                                <span x-text="'$' + formatPrice(getWholesalePrice({{ $extension->id }}))"></span>
                            </td>
                            <td class="px-6 py-4 text-sm text-right text-slate-900 dark:text-white font-medium">
                                <span x-text="'$' + formatPrice(getRetailPrice({{ $extension->id }}))"></span>
                            </td>
                            <td class="px-6 py-4 text-sm text-right">
                                <span x-text="'$' + formatPrice(getMargin({{ $extension->id }}))"></span>
                                <br>
                                <span class="text-emerald-600 dark:text-emerald-400 text-xs">
                                    <span x-text="getMarginPercent({{ $extension->id }}) + '%'"></span>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                @if($resellerPricing?->enabled)
                                    <span class="px-2 py-1 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 rounded text-xs font-medium">Enabled</span>
                                @else
                                    <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded text-xs font-medium">Disabled</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-right">
                                <button @click="openEditModal({{ $extension->id }})" class="text-blue-600 dark:text-blue-400 hover:text-blue-900 dark:hover:text-blue-300 transition">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">
                                No domain extensions available
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Modal -->
    <div x-show="showModal" @click="showModal = false" class="fixed inset-0 bg-black/50 z-40"></div>

    <div x-show="showModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div @click.stop class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 max-w-md w-full">
            <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-6">Edit Domain Pricing</h2>

            <form @submit.prevent="savePricing" class="space-y-6">
                <!-- Extension Name (display only) -->
                <div>
                    <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Extension</label>
                    <input type="text" x-model="selectedExtension" disabled class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-slate-100 dark:bg-slate-800 rounded-lg text-slate-600 dark:text-slate-400 text-sm">
                </div>

                <!-- Wholesale Price (display only) -->
                <div>
                    <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Wholesale Price</label>
                    <div class="relative">
                        <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                        <input type="text" x-model="wholesalePrice" disabled class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-slate-100 dark:bg-slate-800 rounded-lg text-slate-600 dark:text-slate-400 text-sm">
                    </div>
                </div>

                <!-- Your Price (editable) -->
                <div>
                    <label for="modal_retail_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Your Price</label>
                    <div class="relative">
                        <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                        <input type="number" id="modal_retail_price" x-model.number="retailPrice" @input="calculateModalMargin()" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                    </div>
                </div>

                <!-- Margin Preview -->
                <div class="p-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                    <p class="text-xs font-semibold text-emerald-900 dark:text-emerald-300 uppercase tracking-wide mb-2">Your Margin</p>
                    <p class="text-lg font-bold text-emerald-900 dark:text-emerald-300">
                        <span x-text="'$' + modalMargin.toFixed(2)"></span>
                        <span x-text="'(' + modalMarginPercent.toFixed(1) + '%)'"></span>
                    </p>
                </div>

                <!-- Enabled Checkbox -->
                <div>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" x-model="enabled" class="w-4 h-4 text-blue-600 rounded">
                        <span class="text-sm text-slate-700 dark:text-slate-300">Enabled</span>
                    </label>
                </div>

                <!-- Form Actions -->
                <div class="flex gap-3 pt-4">
                    <button type="button" @click="showModal = false" class="flex-1 px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                        Save
                    </button>
                </div>
            </form>
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
        wholesalePrice: null,
        enabled: true,
        modalMargin: 0,
        modalMarginPercent: 0,
        filteredExtensions: [],
        extensions: @json($extensions->map(fn($e) => [
            'id' => $e->id,
            'extension' => $e->extension,
            'pricing' => $e->pricing,
            'resellerPricing' => $e->resellerPricing,
        ])),
        periods: @json($periods),
        get selectedExtension() {
            const ext = this.extensions.find(e => e.id === this.selectedExtensionId);
            return ext ? '.' + ext.extension : '';
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
                this.retailPrice = resellerPricing?.retail_price || 0;
                this.enabled = resellerPricing?.enabled ?? true;
                this.calculateModalMargin();
            }

            this.showModal = true;
        },
        calculateModalMargin() {
            const wholesale = parseFloat(this.wholesalePrice) || 0;
            const retail = parseFloat(this.retailPrice) || 0;

            this.modalMargin = retail - wholesale;
            this.modalMarginPercent = wholesale > 0 ? (this.modalMargin / wholesale) * 100 : 0;
        },
        getWholesalePrice(extensionId) {
            const ext = this.extensions.find(e => e.id === extensionId);
            if (!ext) return 0;
            const pricing = ext.pricing.find(p => p.period_years == this.selectedPeriod);
            return pricing?.price || 0;
        },
        getRetailPrice(extensionId) {
            const ext = this.extensions.find(e => e.id === extensionId);
            if (!ext) return 0;
            const pricing = ext.resellerPricing.find(p => p.period_years == this.selectedPeriod);
            return pricing?.retail_price || 0;
        },
        getMargin(extensionId) {
            const wholesale = this.getWholesalePrice(extensionId);
            const retail = this.getRetailPrice(extensionId);
            return retail - wholesale;
        },
        getMarginPercent(extensionId) {
            const wholesale = this.getWholesalePrice(extensionId);
            if (wholesale === 0) return 0;
            const margin = this.getMargin(extensionId);
            return ((margin / wholesale) * 100).toFixed(1);
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
