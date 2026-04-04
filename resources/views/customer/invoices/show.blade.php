@extends('layouts.customer')

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
            @if($invoice->status === 'paid')
                bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
            @elseif($invoice->status === 'unpaid')
                bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
            @elseif($invoice->status === 'draft')
                bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
            @elseif($invoice->status === 'overdue')
                bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
            @elseif($invoice->status === 'cancelled')
                bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
            @else
                bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
            @endif
        ">
            {{ ucfirst($invoice->status) }}
        </span>
    </div>

    <!-- Invoice Card -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <!-- Top Stripe -->
        <div class="h-1 bg-gradient-to-r
            @if($invoice->status === 'paid')
                from-emerald-500 to-emerald-600
            @elseif($invoice->status === 'unpaid')
                from-amber-500 to-amber-600
            @elseif($invoice->status === 'draft')
                from-slate-500 to-slate-600
            @elseif($invoice->status === 'overdue')
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
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $item->product->name ?? 'Unknown Product' }}</p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400">{{ $item->description }}</p>
                                    </td>
                                    <td class="py-3 px-3 text-right text-sm text-slate-900 dark:text-white">{{ $item->quantity }}</td>
                                    <td class="py-3 px-3 text-right text-sm text-slate-900 dark:text-white">${{ number_format($item->unit_price, 2) }}</td>
                                    <td class="py-3 px-3 text-right text-sm font-medium text-slate-900 dark:text-white">${{ number_format($item->amount, 2) }}</td>
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
                        <span class="text-sm font-medium text-slate-900 dark:text-white">${{ number_format($invoice->subtotal, 2) }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-slate-200 dark:border-slate-700 mb-3">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Tax</span>
                        <span class="text-sm font-medium text-slate-900 dark:text-white">${{ number_format($invoice->tax, 2) }}</span>
                    </div>
                    <div class="flex justify-between py-3 bg-slate-50 dark:bg-slate-800 px-3 rounded">
                        <span class="text-base font-bold text-slate-900 dark:text-white">Total Due</span>
                        <span class="text-lg font-bold text-slate-900 dark:text-white">${{ number_format($invoice->total, 2) }}</span>
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

            <!-- Payment Status & Actions -->
            <div class="border-t border-slate-200 dark:border-slate-700 pt-8">
                @if ($invoice->status->value === 'paid')
                    <div class="mb-6 p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                        <p class="text-sm font-medium text-emerald-700 dark:text-emerald-300">
                            <svg class="w-5 h-5 inline mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            Thank you! This invoice has been paid.
                        </p>
                    </div>
                @else
                    <!-- Payment Panel -->
                    <div x-data="{ paymentTab: 'mpesa', processing: false, message: '', phone: '' }" class="mb-6">
                        <!-- Tabs -->
                        <div class="flex gap-2 mb-6 border-b border-slate-200 dark:border-slate-800">
                            <button @click="paymentTab = 'mpesa'" :class="paymentTab === 'mpesa' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-2 font-medium text-sm transition-colors">
                                M-Pesa
                            </button>
                            <button @click="paymentTab = 'bank'" :class="paymentTab === 'bank' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-2 font-medium text-sm transition-colors">
                                Bank Transfer
                            </button>
                        </div>

                        <!-- M-Pesa Tab -->
                        <div x-show="paymentTab === 'mpesa'" class="space-y-4 pb-6">
                            <form @submit.prevent="async function() {
                                this.processing = true;
                                this.message = '';

                                try {
                                    const response = await fetch('{{ route("customer.mpesa.initiate", $invoice) }}', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-CSRF-Token': document.querySelector('meta[name=\"csrf-token\"]').getAttribute('content'),
                                        },
                                        body: JSON.stringify({
                                            phone: this.phone,
                                        }),
                                    });
                                    const data = await response.json();
                                    this.processing = false;
                                    if (data.success) {
                                        this.message = 'success: ' + data.message;
                                    } else {
                                        this.message = 'error: ' + data.message;
                                    }
                                } catch (error) {
                                    this.processing = false;
                                    this.message = 'error: ' + (error.message || 'Network error');
                                }
                            }()">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Phone Number</label>
                                        <input type="tel" x-model="phone" placeholder="0712345678 or +254712345678" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm" required>
                                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Enter your M-Pesa phone number. You'll receive a prompt to enter your PIN.</p>
                                    </div>
                                    <div class="flex gap-2 items-center">
                                        <button type="submit" :disabled="processing" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white font-medium rounded-lg transition text-sm">
                                            <span x-show="!processing">Pay Ksh {{ number_format($invoice->getAmountRemaining(), 0) }}</span>
                                            <span x-show="processing">Processing...</span>
                                        </button>
                                        <div x-show="message" :class="message.includes('success') ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'" class="text-sm" x-text="message"></div>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Bank Transfer Tab -->
                        <div x-show="paymentTab === 'bank'" class="space-y-4 pb-6">
                            @php
                                $bankName = \App\Models\Setting::getValue('bank_name', '');
                                $accountName = \App\Models\Setting::getValue('bank_account_name', '');
                                $accountNumber = \App\Models\Setting::getValue('bank_account_number', '');
                                $branch = \App\Models\Setting::getValue('bank_branch', '');
                                $swiftCode = \App\Models\Setting::getValue('bank_swift_code', '');
                            @endphp

                            <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4 space-y-3 mb-4">
                                <h4 class="font-semibold text-slate-900 dark:text-white">Bank Details</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                    @if ($bankName)
                                        <div>
                                            <p class="text-slate-600 dark:text-slate-400">Bank Name</p>
                                            <p class="font-medium text-slate-900 dark:text-white">{{ $bankName }}</p>
                                        </div>
                                    @endif
                                    @if ($accountName)
                                        <div>
                                            <p class="text-slate-600 dark:text-slate-400">Account Name</p>
                                            <p class="font-medium text-slate-900 dark:text-white">{{ $accountName }}</p>
                                        </div>
                                    @endif
                                    @if ($accountNumber)
                                        <div>
                                            <p class="text-slate-600 dark:text-slate-400">Account Number</p>
                                            <p class="font-medium text-slate-900 dark:text-white">{{ $accountNumber }}</p>
                                        </div>
                                    @endif
                                    @if ($branch)
                                        <div>
                                            <p class="text-slate-600 dark:text-slate-400">Branch</p>
                                            <p class="font-medium text-slate-900 dark:text-white">{{ $branch }}</p>
                                        </div>
                                    @endif
                                    @if ($swiftCode)
                                        <div>
                                            <p class="text-slate-600 dark:text-slate-400">SWIFT Code</p>
                                            <p class="font-medium text-slate-900 dark:text-white">{{ $swiftCode }}</p>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                                <p class="text-sm text-blue-700 dark:text-blue-300">
                                    <strong>Important:</strong> Please use invoice number <code class="bg-blue-100 dark:bg-blue-900 px-2 py-1 rounded">{{ $invoice->invoice_number }}</code> as your payment reference.
                                </p>
                            </div>

                            <p class="text-xs text-slate-600 dark:text-slate-400 mt-4">After sending the payment, contact support with your bank transfer receipt for payment confirmation.</p>
                        </div>
                    </div>
                @endif

                <!-- Download PDF Button -->
                <div class="mb-6">
                    <a href="{{ route('customer.invoices.download', $invoice) }}" class="inline-flex items-center px-6 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-medium rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition text-sm">
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
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">Ksh {{ number_format($payment->amount, 0) }}</p>
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
@endsection
