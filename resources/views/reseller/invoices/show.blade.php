@extends('layouts.reseller')

@section('title', 'Invoice ' . $invoice->invoice_number)

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Invoice dated {{ $invoice->created_at->format('F d, Y') }}</p>
        </div>
        <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-medium
            @if($invoice->status->value === 'paid')
                bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
            @elseif($invoice->status->value === 'unpaid')
                bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
            @elseif($invoice->status->value === 'draft')
                bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
            @elseif($invoice->status->value === 'overdue')
                bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
            @elseif($invoice->status->value === 'cancelled')
                bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
            @else
                bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
            @endif
        ">
            {{ ucfirst($invoice->status->value) }}
        </span>
    </div>

    <!-- Invoice Card -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <!-- Top Stripe -->
        <div class="h-1 bg-gradient-to-r
            @if($invoice->status->value === 'paid')
                from-emerald-500 to-emerald-600
            @elseif($invoice->status->value === 'unpaid')
                from-amber-500 to-amber-600
            @elseif($invoice->status->value === 'draft')
                from-slate-500 to-slate-600
            @elseif($invoice->status->value === 'overdue')
                from-red-500 to-red-600
            @else
                from-slate-500 to-slate-600
            @endif
        "></div>

        <div class="p-8 md:p-12">
            <!-- Header Section -->
            <div class="mb-8 pb-8 border-b border-slate-200 dark:border-slate-700">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-900 dark:text-white">INVOICE</h2>
                    </div>
                    <div class="text-right text-sm">
                        <p class="text-slate-600 dark:text-slate-400 mb-4">Invoice Number</p>
                        <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</p>
                    </div>
                </div>
            </div>

            <!-- Invoice Metadata -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                <!-- Bill To -->
                <div>
                    <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase mb-3">Bill To</p>
                    <p class="text-lg font-medium text-slate-900 dark:text-white">{{ $invoice->user->name }}</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">{{ $invoice->user->email }}</p>
                    @if ($invoice->user->address)
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2">{{ $invoice->user->address }}</p>
                        @if ($invoice->user->city)
                            <p class="text-sm text-slate-600 dark:text-slate-400">{{ $invoice->user->city }}{{ $invoice->user->postal_code ? ', ' . $invoice->user->postal_code : '' }}</p>
                        @endif
                    @endif
                </div>

                <!-- Invoice Details -->
                <div class="text-right">
                    <div class="mb-4">
                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase mb-1">Invoice Date</p>
                        <p class="text-sm text-slate-900 dark:text-white">{{ $invoice->created_at->format('F d, Y') }}</p>
                    </div>
                    @if ($invoice->due_date)
                        <div>
                            <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase mb-1">Due Date</p>
                            <p class="text-sm text-slate-900 dark:text-white">{{ $invoice->due_date->format('F d, Y') }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Line Items -->
            @if ($invoice->items->count() > 0)
                <div class="mb-8">
                    <table class="w-full mb-4">
                        <thead>
                            <tr class="border-b-2 border-slate-300 dark:border-slate-600">
                                <th class="text-left py-3 px-3 text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Description</th>
                                <th class="text-right py-3 px-3 text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Qty</th>
                                <th class="text-right py-3 px-3 text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Unit Price</th>
                                <th class="text-right py-3 px-3 text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice->items as $item)
                                <tr class="border-b border-slate-200 dark:border-slate-700">
                                    <td class="py-3 px-3">
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">
                                            @if($item->domain_id)
                                                Domain
                                            @else
                                                {{ $item->product->name ?? 'Unknown Product' }}
                                            @endif
                                        </p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400">{{ $item->description }}</p>
                                    </td>
                                    <td class="py-3 px-3 text-right text-sm text-slate-900 dark:text-white">{{ $item->quantity }}</td>
                                    <td class="py-3 px-3 text-right text-sm text-slate-900 dark:text-white">KES {{ number_format($item->unit_price, 2) }}</td>
                                    <td class="py-3 px-3 text-right text-sm font-medium text-slate-900 dark:text-white">KES {{ number_format($item->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <!-- Totals -->
            <div class="flex justify-end mb-8">
                <div class="w-full md:w-80">
                    <div class="flex justify-between py-2 border-b border-slate-200 dark:border-slate-700 mb-2">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Subtotal</span>
                        <span class="text-sm font-medium text-slate-900 dark:text-white">KES {{ number_format($invoice->subtotal, 2) }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-slate-200 dark:border-slate-700 mb-3">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Tax</span>
                        <span class="text-sm font-medium text-slate-900 dark:text-white">KES {{ number_format($invoice->tax, 2) }}</span>
                    </div>
                    <div class="flex justify-between py-3 bg-purple-50 dark:bg-purple-900/20 px-3 rounded">
                        <span class="text-base font-bold text-slate-900 dark:text-white">Total Due</span>
                        <span class="text-lg font-bold text-slate-900 dark:text-white">KES {{ number_format($invoice->total, 2) }}</span>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            @if ($invoice->notes)
                <div class="mb-8 pb-8 border-t border-slate-200 dark:border-slate-700 pt-8">
                    <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase mb-2">Notes</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400">{{ $invoice->notes }}</p>
                </div>
            @endif

            <!-- Actions -->
            <div class="border-t border-slate-200 dark:border-slate-700 pt-8">
                <!-- Action Buttons -->
                <div class="flex flex-wrap gap-3 mb-6">
                    <!-- Pay Now Button (for unpaid/overdue invoices) -->
                    @if(in_array($invoice->status->value, ['unpaid', 'overdue']))
                        <button @click="openPaymentModal()" class="inline-flex items-center px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition text-sm">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Pay Now
                        </button>
                    @endif

                    <!-- Download PDF Button -->
                    <a href="{{ route('reseller.invoices.download', $invoice) }}" class="inline-flex items-center px-6 py-2 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 font-medium rounded-lg hover:bg-purple-200 dark:hover:bg-purple-900/50 transition text-sm">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        Download PDF
                    </a>
                </div>

                <!-- Payment History -->
                @if ($invoice->payments->count() > 0)
                    <div class="mt-8 pt-8 border-t border-slate-200 dark:border-slate-700">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Payment History</p>
                        <div class="space-y-2">
                            @foreach ($invoice->payments as $payment)
                                <div class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-800 rounded">
                                    <div>
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">KES {{ number_format($payment->amount, 2) }}</p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400">{{ $payment->payment_method?->label() }} • {{ $payment->created_at->format('M d, Y') }}</p>
                                    </div>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        @if($payment->status->value === 'completed')
                                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                                        @elseif($payment->status->value === 'pending')
                                            bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                                        @elseif($payment->status->value === 'failed')
                                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                        @else
                                            bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                                        @endif
                                    ">
                                        {{ $payment->status->label() }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Payment Method Modal -->
<div x-data="invoicePaymentModal()" x-show="showPaymentModal" x-transition class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center" @click.outside="closePaymentModal()">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 max-w-lg w-full mx-4">
        <!-- Header -->
        <div class="flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Select Payment Method</h3>
            <button @click="closePaymentModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Body -->
        <div class="p-6 space-y-4">
            <template x-if="loadingGateways">
                <div class="flex items-center justify-center py-8">
                    <div class="w-5 h-5 bg-purple-600 rounded-full animate-bounce"></div>
                </div>
            </template>

            <template x-if="!loadingGateways && gateways.length > 0">
                <form @submit.prevent="submitPayment()">
                    <div class="space-y-3">
                        <template x-for="(gateway, key) in Object.entries(gateways)" :key="key">
                            <label class="flex items-start p-4 border-2 rounded-lg cursor-pointer transition" :class="selectedGateway === key[0] ? 'border-purple-500 bg-purple-50 dark:bg-purple-950' : 'border-slate-200 dark:border-slate-700 hover:border-purple-300'">
                                <input type="radio" name="method" :value="key[0]" x-model="selectedGateway" class="w-5 h-5 mt-1 rounded-full border-slate-300 text-purple-600 focus:ring-0 focus:border-purple-500 transition">
                                <div class="ml-4 flex-1">
                                    <p class="font-semibold text-slate-900 dark:text-white" x-text="key[1].label"></p>
                                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1" x-text="key[1].description"></p>
                                </div>
                            </label>
                        </template>
                    </div>

                    <!-- M-Pesa Phone Input -->
                    <template x-if="selectedGateway === 'mpesa'">
                        <div class="mt-4 p-4 bg-purple-50 dark:bg-purple-950 border border-purple-200 dark:border-purple-800 rounded-lg">
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Phone Number</label>
                            <input type="tel" name="phone" placeholder="254712345678" x-model="mpesaPhone" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                            <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Format: 254XXXXXXXXX</p>
                        </div>
                    </template>

                    <!-- Action Buttons -->
                    <div class="flex gap-3 mt-6 pt-6 border-t border-slate-200 dark:border-slate-700">
                        <button type="button" @click="closePaymentModal()" class="flex-1 px-4 py-2 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition">
                            Cancel
                        </button>
                        <button type="submit" :disabled="submitting" class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:bg-slate-400 text-white font-medium rounded-lg transition">
                            <span x-show="!submitting">Continue to Payment</span>
                            <span x-show="submitting">Processing...</span>
                        </button>
                    </div>
                </form>
            </template>

            <template x-if="!loadingGateways && gateways.length === 0">
                <div class="text-center py-8">
                    <p class="text-slate-600 dark:text-slate-400">No payment methods available. Please contact support.</p>
                </div>
            </template>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
function invoicePaymentModal() {
    return {
        showPaymentModal: false,
        loadingGateways: false,
        gateways: {},
        selectedGateway: '',
        mpesaPhone: '',
        submitting: false,

        async openPaymentModal() {
            this.showPaymentModal = true;
            this.loadingGateways = true;
            this.selectedGateway = '';
            this.mpesaPhone = '';

            try {
                const res = await fetch('{{ route("reseller.payment.select-method", $invoice) }}', {
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                if (res.ok) {
                    const data = await res.json();
                    this.gateways = data.gateways || {};
                    if (Object.keys(this.gateways).length > 0) {
                        this.selectedGateway = Object.keys(this.gateways)[0];
                    }
                } else {
                    alert('Failed to load payment methods');
                }
            } catch (error) {
                console.error('Error loading gateways:', error);
                alert('Error loading payment methods');
            } finally {
                this.loadingGateways = false;
            }
        },

        closePaymentModal() {
            this.showPaymentModal = false;
        },

        async submitPayment() {
            if (!this.selectedGateway) {
                alert('Please select a payment method');
                return;
            }

            this.submitting = true;

            try {
                const formData = new FormData();
                formData.append('method', this.selectedGateway);
                if (this.selectedGateway === 'mpesa' && this.mpesaPhone) {
                    formData.append('phone', this.mpesaPhone);
                }

                const res = await fetch('{{ route("reseller.payment.initiate", $invoice) }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                    },
                    body: formData
                });

                if (res.ok) {
                    window.location.href = res.url || '{{ route("reseller.invoices.show", $invoice) }}';
                } else {
                    const text = await res.text();
                    alert('Payment initiation failed: ' + text);
                }
            } catch (error) {
                console.error('Payment error:', error);
                alert('Error initiating payment: ' + error.message);
            } finally {
                this.submitting = false;
            }
        }
    }
}
</script>
@endpush
