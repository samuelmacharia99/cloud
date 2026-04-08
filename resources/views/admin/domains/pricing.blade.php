@extends('layouts.admin')

@section('title', 'Domain Pricing')

@section('breadcrumb')
<div class="flex items-center gap-2">
    <a href="{{ route('admin.domains.index') }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Domains</a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Pricing</p>
</div>
@endsection

@section('content')
<div x-data="pricingManager()" x-init="@if ($errors->any()) showAddExtensionModal = true @endif" class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Domain Pricing</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Configure retail and wholesale pricing for all domain extensions.</p>
        </div>
        <button @click="openAddExtensionModal()" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg transition">
            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Extension
        </button>
    </div>

    <!-- Success Message -->
    @if (session('success'))
        <div class="px-4 py-3 bg-emerald-50 dark:bg-emerald-950 border border-emerald-200 dark:border-emerald-800 rounded-lg flex items-center justify-between">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">✓ {{ session('success') }}</p>
            <button @click="this.parentElement.remove()" class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-800">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
        </div>
    @endif

    <!-- Extensions Grid -->
    <div class="space-y-3">
        @forelse ($extensions as $extension)
            <div x-data="{ expanded: false }" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <!-- Card Header (Always Visible) -->
                <button
                    @click="expanded = !expanded"
                    type="button"
                    class="w-full px-6 py-4 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between transition">

                    <!-- Left: Extension Info -->
                    <div class="flex items-center gap-4 flex-1 text-left">
                        <div>
                            <h2 class="text-lg font-bold text-slate-900 dark:text-white">{{ $extension->extension }}</h2>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-0.5">{{ $extension->description ?? 'No description' }}</p>
                        </div>
                    </div>

                    <!-- Middle: Badge -->
                    <div class="flex items-center gap-2 mr-4">
                        <span class="px-3 py-1 bg-slate-200 dark:bg-slate-700 text-slate-900 dark:text-white rounded-full text-xs font-medium">
                            {{ $extension->registrar }}
                        </span>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $extension->enabled ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300' }}">
                            {{ $extension->enabled ? '●' : '○' }}
                        </span>
                    </div>

                    <!-- Right: Expand/Collapse Icon -->
                    <div class="flex items-center">
                        <svg :class="expanded ? 'rotate-180' : ''" class="w-5 h-5 text-slate-600 dark:text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                        </svg>
                    </div>
                </button>

                <!-- Expandable Pricing Table -->
                <div
                    x-show="expanded"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="overflow-x-auto"
                    style="display: none;">

                    <table class="w-full">
                        <thead class="bg-slate-50 dark:bg-slate-800 border-t border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Period</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Retail</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Wholesale</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Margin</th>
                                <th class="px-6 py-3 text-center text-sm font-semibold text-slate-900 dark:text-white">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach ([1, 2, 3, 5, 10] as $period)
                                @php
                                    $pricing = $extension->getPricingForPeriod($period);
                                    $retail = $pricing->get('retail');
                                    $wholesale = $pricing->get('wholesale');
                                @endphp
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                    <td class="px-6 py-3 text-sm font-medium text-slate-900 dark:text-white">
                                        {{ $period }}y
                                    </td>
                                    <td class="px-6 py-3 text-sm">
                                        @if ($retail)
                                            <div class="font-bold text-slate-900 dark:text-white">${{ number_format($retail->price, 2) }}</div>
                                            @if ($retail->setup_fee > 0)
                                                <span class="text-xs text-slate-600 dark:text-slate-400">+${{ number_format($retail->setup_fee, 2) }} setup</span>
                                            @endif
                                        @else
                                            <span class="text-slate-500 dark:text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 text-sm">
                                        @if ($wholesale)
                                            <div class="font-bold text-slate-900 dark:text-white">${{ number_format($wholesale->price, 2) }}</div>
                                            @if ($wholesale->setup_fee > 0)
                                                <span class="text-xs text-slate-600 dark:text-slate-400">+${{ number_format($wholesale->setup_fee, 2) }} setup</span>
                                            @endif
                                        @else
                                            <span class="text-slate-500 dark:text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 text-sm">
                                        @if ($retail && $wholesale)
                                            <div class="font-bold text-emerald-600 dark:text-emerald-400">${{ number_format($retail->price - $wholesale->price, 2) }}</div>
                                            <span class="text-xs text-slate-600 dark:text-slate-400">{{ round((($retail->price - $wholesale->price) / $wholesale->price) * 100, 1) }}%</span>
                                        @else
                                            <span class="text-slate-500 dark:text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 text-center">
                                        <button @click="openPricingModal({{ $extension->id }}, {{ $period }}, {{ json_encode(['retail' => $retail, 'wholesale' => $wholesale]) }})" type="button" class="px-3 py-1 text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition bg-blue-50 dark:bg-blue-950 rounded">
                                            Edit
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-12 text-center">
                <svg class="w-12 h-12 text-slate-300 dark:text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                </svg>
                <p class="text-slate-600 dark:text-slate-400 font-medium">No domain extensions configured yet</p>
                <button @click="openAddExtensionModal()" type="button" class="mt-4 text-blue-600 dark:text-blue-400 hover:underline font-medium">Add your first extension</button>
            </div>
        @endforelse
    </div>

    <!-- Add Extension Modal -->
    <div x-show="showAddExtensionModal" @click.outside="closeAddExtensionModal()" x-transition class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" style="display: none;">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 max-w-md w-full mx-4 p-6 space-y-4 max-h-[90vh] overflow-y-auto">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Add Domain Extension</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400">Create a new domain extension (.com, .co.ke, etc.)</p>

            <!-- Error Messages -->
            @if ($errors->any())
                <div class="p-3 bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 rounded-lg">
                    <p class="text-sm font-medium text-red-800 dark:text-red-200 mb-2">❌ Error: Please fix the following:</p>
                    <ul class="text-sm text-red-700 dark:text-red-300 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>• {{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.domain-extensions.store') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="extension" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Extension <span class="text-red-600">*</span></label>
                    <input
                        type="text"
                        id="extension"
                        name="extension"
                        placeholder=".com"
                        value="{{ old('extension') }}"
                        required
                        class="w-full px-4 py-2 border {{ $errors->has('extension') ? 'border-red-500' : 'border-slate-300 dark:border-slate-600' }} bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">e.g., .com, .co.ke, .org, .ke</p>
                    @error('extension')<p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Description</label>
                    <input
                        type="text"
                        id="description"
                        name="description"
                        placeholder="Commercial domain"
                        value="{{ old('description') }}"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                </div>

                <div>
                    <label for="registrar" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registrar <span class="text-red-600">*</span></label>
                    <input
                        type="text"
                        id="registrar"
                        name="registrar"
                        placeholder="ICANN, KENIC, etc."
                        value="{{ old('registrar') }}"
                        required
                        class="w-full px-4 py-2 border {{ $errors->has('registrar') ? 'border-red-500' : 'border-slate-300 dark:border-slate-600' }} bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    @error('registrar')<p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="transfer_price" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Transfer Price</label>
                    <div class="flex items-center gap-2">
                        <span class="text-slate-900 dark:text-white font-medium">$</span>
                        <input
                            type="number"
                            id="transfer_price"
                            name="transfer_price"
                            placeholder="0.00"
                            value="{{ old('transfer_price') }}"
                            step="0.01"
                            min="0"
                            class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Price charged for domain transfers (one-time)</p>
                </div>

                <div class="space-y-2">
                    <label for="dns_management" class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            id="dns_management"
                            name="dns_management"
                            value="1"
                            checked
                            class="w-4 h-4 rounded border-slate-300 text-blue-600">
                        <span class="text-sm text-slate-700 dark:text-slate-300">DNS Management Available</span>
                    </label>
                    <label for="auto_renewal" class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            id="auto_renewal"
                            name="auto_renewal"
                            value="1"
                            checked
                            class="w-4 h-4 rounded border-slate-300 text-blue-600">
                        <span class="text-sm text-slate-700 dark:text-slate-300">Auto Renewal Available</span>
                    </label>
                </div>

                <div class="flex gap-3 pt-4">
                    <button type="button" @click="closeAddExtensionModal()" class="flex-1 px-4 py-2 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg transition">
                        Add Extension
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Pricing Modal -->
    <div x-show="showPricingModal" @click.outside="showPricingModal = false" x-transition class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" style="display: none;">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 max-w-xl w-full mx-4 p-6 space-y-4 max-h-[90vh] overflow-y-auto">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Edit Pricing</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400">Configure both retail (customer) and wholesale (reseller) pricing</p>

            <form method="POST" action="{{ route('admin.domains.pricing.store') }}" class="space-y-4">
                @csrf

                <input type="hidden" name="domain_extension_id" x-model="selectedExtensionId">
                <input type="hidden" name="period_years" x-model="selectedPeriod">

                <!-- Retail Pricing Section -->
                <div class="border-2 border-emerald-200 dark:border-emerald-800 rounded-lg p-4 space-y-3">
                    <h4 class="font-semibold text-slate-900 dark:text-white text-sm flex items-center gap-2">
                        <span class="w-6 h-6 rounded-full bg-emerald-100 dark:bg-emerald-950 flex items-center justify-center text-xs font-bold text-emerald-700 dark:text-emerald-300">$</span>
                        Retail (Customer)
                    </h4>

                    <div>
                        <label for="retail_price" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Annual Price <span class="text-red-600">*</span></label>
                        <div class="flex items-center gap-2">
                            <span class="text-slate-900 dark:text-white font-medium">$</span>
                            <input type="number" id="retail_price" name="retail_price" x-model="retailPrice" placeholder="9.99" step="0.01" min="0" required class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-emerald-500 dark:focus:ring-emerald-400 text-sm">
                        </div>
                    </div>

                    <div>
                        <label for="retail_setup_fee" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Setup Fee (Optional)</label>
                        <div class="flex items-center gap-2">
                            <span class="text-slate-900 dark:text-white font-medium">$</span>
                            <input type="number" id="retail_setup_fee" name="retail_setup_fee" x-model="retailSetupFee" placeholder="0.00" step="0.01" min="0" class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-emerald-500 dark:focus:ring-emerald-400 text-sm">
                        </div>
                    </div>
                </div>

                <!-- Wholesale Pricing Section -->
                <div class="border-2 border-blue-200 dark:border-blue-800 rounded-lg p-4 space-y-3">
                    <h4 class="font-semibold text-slate-900 dark:text-white text-sm flex items-center gap-2">
                        <span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-950 flex items-center justify-center text-xs font-bold text-blue-700 dark:text-blue-300">$</span>
                        Wholesale (Reseller)
                    </h4>

                    <div>
                        <label for="wholesale_price" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Annual Price <span class="text-red-600">*</span></label>
                        <div class="flex items-center gap-2">
                            <span class="text-slate-900 dark:text-white font-medium">$</span>
                            <input type="number" id="wholesale_price" name="wholesale_price" x-model="wholesalePrice" placeholder="5.99" step="0.01" min="0" required class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                        </div>
                    </div>

                    <div>
                        <label for="wholesale_setup_fee" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Setup Fee (Optional)</label>
                        <div class="flex items-center gap-2">
                            <span class="text-slate-900 dark:text-white font-medium">$</span>
                            <input type="number" id="wholesale_setup_fee" name="wholesale_setup_fee" x-model="wholesaleSetupFee" placeholder="0.00" step="0.01" min="0" class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                        </div>
                    </div>
                </div>

                <!-- Margin Info -->
                <div class="bg-emerald-50 dark:bg-emerald-950 rounded-lg p-3 border border-emerald-200 dark:border-emerald-800">
                    <p class="text-sm font-medium text-emerald-900 dark:text-emerald-100">Reseller Margin:</p>
                    <p class="text-2xl font-bold text-emerald-700 dark:text-emerald-300 mt-1">
                        <span x-text="'$' + (parseFloat(retailPrice || 0) - parseFloat(wholesalePrice || 0)).toFixed(2)"></span>
                        <span class="text-sm text-emerald-600 dark:text-emerald-400 ml-2">(<span x-text="((parseFloat(retailPrice || 0) - parseFloat(wholesalePrice || 0)) / parseFloat(wholesalePrice || 1) * 100).toFixed(1) + '%'"></span>)</span>
                    </p>
                </div>

                <div class="flex gap-3 pt-4">
                    <button type="button" @click="showPricingModal = false" class="flex-1 px-4 py-2 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                        Save Pricing
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function pricingManager() {
    return {
        showAddExtensionModal: false,
        showPricingModal: false,
        selectedExtensionId: null,
        selectedPeriod: null,
        retailPrice: '',
        retailSetupFee: '',
        wholesalePrice: '',
        wholesaleSetupFee: '',

        openAddExtensionModal() {
            this.showAddExtensionModal = true;
        },

        closeAddExtensionModal() {
            this.showAddExtensionModal = false;
        },

        openPricingModal(extensionId, period, pricing) {
            this.selectedExtensionId = extensionId;
            this.selectedPeriod = period;

            // Pre-fill with existing pricing if available
            if (pricing && pricing.retail) {
                this.retailPrice = parseFloat(pricing.retail.price).toFixed(2);
                this.retailSetupFee = parseFloat(pricing.retail.setup_fee || 0).toFixed(2);
            } else {
                this.retailPrice = '';
                this.retailSetupFee = '0.00';
            }

            if (pricing && pricing.wholesale) {
                this.wholesalePrice = parseFloat(pricing.wholesale.price).toFixed(2);
                this.wholesaleSetupFee = parseFloat(pricing.wholesale.setup_fee || 0).toFixed(2);
            } else {
                this.wholesalePrice = '';
                this.wholesaleSetupFee = '0.00';
            }

            this.showPricingModal = true;
        }
    }
}
</script>
@endsection
